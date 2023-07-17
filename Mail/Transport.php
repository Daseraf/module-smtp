<?php

/**
 * @author Mygento Team
 * @copyright 2023 Mygento (https://www.mygento.com)
 * @package Mygento_Smtp
 */

namespace Mygento\Smtp\Mail;

use Closure;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Mail\Address;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\TransportInterface;
use Mygento\Smtp\Api\Data;
use Mygento\Smtp\Api\LogRepositoryInterface;
use Mygento\Smtp\Model\Source\Status;
use Psr\Log\LoggerInterface;

class Transport
{
    public const XML_PATH_EMAIL_LOG = 'system/smtp/log';

    public function __construct(
        private LogRepositoryInterface $repo,
        private Data\LogInterfaceFactory $factory,
        private ScopeConfigInterface $config,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param TransportInterface $subject
     * @param Closure $proceed
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSendMessage(TransportInterface $subject, Closure $proceed): void
    {
        if (!$this->config->isSetFlag(self::XML_PATH_EMAIL_LOG)) {
            $proceed();

            return;
        }

        /** @var Data\LogInterface $entity */
        $entity = $this->factory->create();

        try {
            $this->fillEntity($entity, $subject->getMessage());
            $proceed();

            $entity->setStatus(Status::STATUS_SUCCESS);
            $this->repo->save($entity);
        } catch (MailException $e) {
            $entity->setError($e->getPrevious()->getMessage());
            $entity->setStatus(Status::STATUS_ERROR);

            throw $e;
        } catch (LocalizedException $e) {
            $entity->setStatus(Status::STATUS_ERROR);
            $entity->setError($e->getMessage());
            $this->logger->error($e->getMessage(), ['exception' => $e]);
        } finally {
            $this->repo->save($entity);
        }
    }

    private function formatList(iterable $list): string
    {
        return implode(
            ',',
            array_map(fn (Address $address) => $address->getEmail(), $list)
        );
    }

    private function fillEntity(Data\LogInterface $entity, EmailMessageInterface $message): Data\LogInterface
    {
        if ($message->getSender()) {
            $entity->setSender(
                $message->getSender()->getName() . ' <' . $message->getSender()->getEmail() . '>'
            );
        } elseif (is_iterable($message->getFrom()) && count($message->getFrom()) > 0) {
            $f = $message->getFrom();
            $from = reset($f);
            $entity->setSender(
                $from->getName() . ' <' . $from->getEmail() . '>'
            );
        }

        $entity->setRecipient($this->formatList($message->getTo()));
        $entity->setSubject($message->getSubject());
        $messageBody = quoted_printable_decode($message->getBodyText());
        $content = htmlspecialchars($messageBody);
        $entity->setContent($content);

        if (is_iterable($message->getCc()) && count($message->getCc()) > 0) {
            $entity->setCc($this->formatList($message->getCc()));
        }
        if (is_iterable($message->getBcc()) && count($message->getBcc()) > 0) {
            $entity->setBcc($this->formatList($message->getBcc()));
        }

        return $entity;
    }
}
