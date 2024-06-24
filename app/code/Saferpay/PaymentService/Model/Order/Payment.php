<?php
/**
 * Saferpay PaymentService
 *
 * NOTICE OF LICENSE
 *
 * Once you have purchased the software with PIT Solutions AG / Six Payment services AG
 * or one of its  authorised resellers and provided that you comply with the conditions of this contract,
 * PIT Solutions AG and Six Payment services AG grants you a non-exclusive license,
 * unlimited in time for the usage of the software in the manner of and for the purposes specified in License.txt
 * available in extension package, according to the subsequent regulations.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to
 * newer versions in the future.
 *
 * @category Saferpay
 * @package Saferpay_PaymentService
 * @author PIT Solutions Pvt. Ltd.
 * @copyright Copyright (c) PIT Solutions AG. (www.pitsolutions.ch) and
 * Six Payment services AG ( https://www.six-payment-services.com/)
 * @license https://www.webshopextension.com/en/licence-agreement-saferpay
 *
 */

namespace Saferpay\PaymentService\Model\Order;

use Exception;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Saferpay\PaymentService\Helper\Constants;
use Saferpay\PaymentService\Helper\ErrorLogger;
use Saferpay\PaymentService\Helper\SecureTransaction;

/**
 * Class Payment
 *
 * @package Saferpay\PaymentService\Model\Order
 */
class Payment extends AbstractModel
{
    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var SecureTransaction
     */
    protected $secureTransactionHelper;

    /**
     * @var ErrorLogger
     */
    protected $logger;

    /**
     * @var InvoiceManagementInterface
     */
    private $invoiceManagement;

    /**
     * Payment constructor.
     *
     * @param OrderRepository $orderRepository
     * @param SecureTransaction $secureTransactionHelper
     * @param ErrorLogger $logger
     * @param InvoiceManagementInterface $invoiceManagement
     * @return void
     */
    public function __construct(
        OrderRepository $orderRepository,
        SecureTransaction $secureTransactionHelper,
        ErrorLogger $logger,
        InvoiceManagementInterface $invoiceManagement
    ) {
        $this->orderRepository = $orderRepository;
        $this->secureTransactionHelper = $secureTransactionHelper;
        $this->logger = $logger;
        $this->invoiceManagement = $invoiceManagement;
    }

    /**
     * Function to Authorize Transaction
     *
     * @param string $txnId
     * @param int $orderId
     * @return boolean
     */
    public function paymentAuthorize($txnId, $orderId)
    {
        try {
            $order = $this->orderRepository->get($orderId);
            $payment = $order->getPayment();
            $payment->setTransactionId($txnId);
            $payment->setIsTransactionClosed(false);
            $amount = $order->getBaseGrandTotal();
            if (version_compare($this->secureTransactionHelper->getVersion(), '2.1.8', '<')) {
                $amount = $order->getGrandTotal();
            }
            $payment->authorize(true, $amount);
            $order->save();
            if (($order->getStatus() != Order::STATE_PROCESSING)) {
                return false;
            }

            return true;
        } catch (Exception $ex) {
            $this->logger->writeErrorLog(
                Constants::LOG_TYPE_CRITICAL,
                'OrderId ' . $orderId . '- Something went wrong during payment authorization',
                [$ex->getMessage()]
            );

            return false;
        }
    }

    /**
     * Function to  Capture Transaction
     *
     * @param string $txnId
     * @param int $orderId
     * @param array $resultArray
     * @return boolean
     */
    public function paymentCapture($txnId, $orderId, $resultArray = [])
    {
        try {
            if (isset($resultArray['api_status']) && $resultArray['api_status'] != Constants::API_SUCCESS_CODE) {
                return false;
            }
            $order = $this->orderRepository->get($orderId);
            $payment = $order->getPayment();
            $payment->setParentTransactionId($txnId);
            if (!empty($resultArray) && isset($resultArray['capture_id'])) {
                $txnId = $resultArray['capture_id'];
            }
            $payment->setTransactionId($txnId);
            if ($order->getStatus() != Order::STATE_PROCESSING) {
                $order->setState(Order::STATE_PROCESSING);
                $order->setStatus(Order::STATE_PROCESSING);
            }
            $payment->setIsTransactionClosed(true);
            $amount = $order->getBaseGrandTotal();
            if (version_compare($this->secureTransactionHelper->getVersion(), '2.1.8', '<')) {
                $amount = $order->getGrandTotal();
            }
            $payment->registerCaptureNotification($amount, true);
            $order->save();
            $paymentMethod = $payment->getMethod();
            $transactionConfigData = $this->secureTransactionHelper->getPaymentMethodConfigData($paymentMethod);
            $sendInvoice = $transactionConfigData['send_invoice_email'];
            if (!$sendInvoice) {
                return true;
            }
            if ($payment->getCreatedInvoice()) {
                $invoice = $payment->getCreatedInvoice();
            } else {
                $invoice = $this->loadInvoiceByTransactionId($payment->getOrder(), $payment->getTransactionId());
            }
            if ($invoice) {
                $this->invoiceManagement->notify($invoice->getId());
            }

            return true;
        } catch (Exception $ex) {
            $this->logger->writeErrorLog(
                Constants::LOG_TYPE_CRITICAL,
                'OrderId ' . $orderId . '- Something went wrong during payment capture',
                [$ex->getMessage()]
            );

            return false;
        }
    }

    /**
     * Function to load invoice by Transaction Id
     *
     * @param int $order
     * @param string $transactionId
     * @return mixed $invoice
     */
    public function loadInvoiceByTransactionId($order, $transactionId)
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ($invoice->getTransactionId() == $transactionId) {
                $invoice->load($invoice->getId());

                return $invoice;
            }
        }
    }

    /**
     * Function to generate invoice
     *
     * @param int $orderId
     * @return boolean
     */
    public function generateInvoice($orderId)
    {
        try {
            $order = $this->orderRepository->get($orderId);
            if (!$order) {
                return false;
            }
            $payment = $order->getPayment();
            $paymentMethod = $payment->getMethod();
            $transactionConfigData = $this->secureTransactionHelper->getPaymentMethodConfigData($paymentMethod);
            $invoiceType = $transactionConfigData['invoice_generation'];
            if ($invoiceType != Constants::INVOICE_AUTO) {
                return false;
            }
            if ($order->hasInvoices()) {
                return false;
            }
            $invoice = $order->prepareInvoice();
            $invoice->register();
            $invoice->setTransactionId($payment->getTransactionId());
            $order->addRelatedObject($invoice);
            $order->save();

            return true;
        } catch (Exception $ex) {
            $this->logger->writeErrorLog(
                Constants::LOG_TYPE_CRITICAL,
                'OrderId ' . $orderId . '- Something went wrong during invoice generation',
                [$ex->getMessage()]
            );

            return false;
        }
    }
}
