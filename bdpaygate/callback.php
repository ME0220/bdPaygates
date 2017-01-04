<?php

$startTime = microtime(true);

// we have to figure out XenForo path
// dirname(dirname(__FILE__)) should work most of the time
// as it was the way XenForo's index.php does
// however, sometimes it may not work...
// so we have to be creative
$parentOfDirOfFile = dirname(dirname(__FILE__));
$scriptFilename = (isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '');
$pathToCheck = '/library/XenForo/Autoloader.php';
$fileDir = false;
if (file_exists($parentOfDirOfFile . $pathToCheck))
{
	$fileDir = $parentOfDirOfFile;
}
if ($fileDir === false AND !empty($scriptFilename))
{
	$parentOfDirOfScriptFilename = dirname(dirname($scriptFilename));
	if (file_exists($parentOfDirOfScriptFilename . $pathToCheck))
	{
		$fileDir = $parentOfDirOfScriptFilename;
	}
}
if ($fileDir === false)
{
	die('XenForo path could not be figured out...');
}
// finished figuring out $fileDir

// change directory to mimics the XenForo environment as much as possible
chdir($fileDir);

require ($fileDir . '/library/XenForo/Autoloader.php');
XenForo_Autoloader::getInstance()->setupAutoloader($fileDir . '/library');

XenForo_Application::initialize($fileDir . '/library', $fileDir);
XenForo_Application::set('page_start_time', $startTime);

$dependencies = new XenForo_Dependencies_Public();
$dependencies->preLoadData();
// requires to get registered event listeners

if (!isset($_GET['p']))
{
	die('Invalid callback request');
}

$processorId = $_GET['p'];
/** @var bdPaygate_Model_Processor $processorModel */
$processorModel = XenForo_Model::create('bdPaygate_Model_Processor');
$names = $processorModel->getProcessorNames();

if (!isset($names[$processorId]))
{
	die('Invalid processor specified');
}

$processor = bdPaygate_Processor_Abstract::create($names[$processorId]);
$request = new Zend_Controller_Request_Http();
$response = new Zend_Controller_Response_Http();

$logMessage = '';
$logDetails = array();
$transactionId = false;
$paymentStatus = false;
$itemId = false;
$amount = false;
$currency = false;

try
{
	$validateResult = false;

	try
	{
		// try to use the validateCallback method version 2 with support for amount and
		// currency extraction
		$validateResult = $processor->validateCallback2($request, $transactionId, $paymentStatus, $logDetails, $itemId, $amount, $currency);
	}
	catch (bdPaygate_Exception_NotImplemented $nie)
	{
		$validateResult = $processor->validateCallback($request, $transactionId, $paymentStatus, $logDetails, $itemId);
	}

	$logDetails[bdPaygate_Processor_Abstract::TRANSACTION_DETAILS_CALLBACK_IP] = (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : false);
	$logMessage = $processor->getLastError();

	if (!$validateResult)
	{
		$paymentStatus = bdPaygate_Processor_Abstract::PAYMENT_STATUS_ERROR;
		$response->setHttpResponseCode(500);
	}
	else
	{
		$processor->saveLastTransaction($transactionId, $paymentStatus, $logDetails);

		if (!empty($transactionId) && $paymentStatus !== bdPaygate_Processor_Abstract::PAYMENT_STATUS_OTHER)
		{
			/** @var bdPaygate_Model_Log $logModel */
			$logModel = $processorModel->getModelFromCache('bdPaygate_Model_Log');
			$existingTransactions = $logModel->getLogs(array('transaction_id' => $transactionId));
			foreach ($existingTransactions as $existingTransaction)
			{
				if ($existingTransaction['log_type'] === $paymentStatus)
				{
					$logMessage = "Transaction {$transactionId}/{$paymentStatus} has already been processed";
					$paymentStatus = bdPaygate_Processor_Abstract::PAYMENT_STATUS_OTHER;

					$response->setHttpResponseCode(403);
				}
			}
		}
	}

	if (in_array($paymentStatus, array(
		bdPaygate_Processor_Abstract::PAYMENT_STATUS_ACCEPTED,
		bdPaygate_Processor_Abstract::PAYMENT_STATUS_REJECTED,
	), true))
	{
		$logMessage = $processor->processTransaction($paymentStatus, $itemId, $amount, $currency);
	}
	else
	{
		$subscriptionId = $processor->getLastSubscriptionId();
		if (!empty($subscriptionId))
		{
			$logMessage = $processorModel->updateSubscriptionForItem($itemId, $processor, $subscriptionId);
		}
	}
}
catch (Exception $e)
{
	$response->setHttpResponseCode(500);
	XenForo_Error::logException($e);

	$paymentStatus = bdPaygate_Processor_Abstract::PAYMENT_STATUS_ERROR;
	$logMessage = 'Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

$processorModel->log($processorId, $transactionId, $paymentStatus, $logMessage, $logDetails);

if (!$processor->redirectOnCallback($request, $paymentStatus, $logMessage))
{
	$response->setBody(htmlspecialchars($logMessage));

	try
	{
		if (!headers_sent())
		{
			$response->sendResponse();
		}
	}
	catch (Exception $e)
	{
		// ignore
	}
}
