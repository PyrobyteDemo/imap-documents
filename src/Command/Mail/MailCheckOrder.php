<?php

namespace App\Command\Mail;

use App\Entity\Order\Order;
use App\Entity\Template\Template;
use App\Entity\User\User;
use App\Exception\OrderByOrderNumberNotFoundException;
use App\Exception\OrderHasBeenCanceled;
use App\Exception\OrderItemNotFoundException;
use App\Service\AMQP\RabbitMQ\RabbitMq;
use App\Service\Imap;
use App\Service\Mail\Handler\MailHandler;
use App\Service\Mailer\MailerService;
use App\Service\Notification\CreateNotificationService;
use App\Service\Order\EditPartnerOrderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Проверка на почте файлов-заказов, которые мы отправили поставщику на согласование
 *
 * Class MailCheckOrder
 * @package App\Command\Mail
 */
class MailCheckOrder extends MailBaseTemplates
{
    protected static $defaultName = 'mail:check:order';
    protected $templateCode = Template::CODE_ORDER;
    private $editPartnerOrderService;

    public function __construct(
        Imap $imap,
        MailHandler $mailHandler,
        EntityManagerInterface $entityManager,
        MailerService $mailerService,
        RabbitMq $rabbitMq,
        LoggerInterface $logger,
        ParameterBagInterface $parameterBag,
        Filesystem $fileSystem,
        EditPartnerOrderService $editPartnerOrderService,
        CreateNotificationService $createNotificationService
    )
    {
        $this->editPartnerOrderService = $editPartnerOrderService;

        parent::__construct(
            $imap,
            $mailHandler,
            $entityManager,
            $mailerService,
            $rabbitMq,
            $logger,
            $parameterBag,
            $fileSystem,
            $createNotificationService
        );
    }

    /**
     * @param User $user
     * @param $parsingResult
     * @param string $templateCode
     * @throws \Exception
     */
    protected function processWithResults(User $user, $parsingResult, string $templateCode)
    {
        if (empty($parsingResult->getOrder())) {
            return;
        }

        /** @var Order $order */
        $order = $parsingResult->getOrder();

        try {
            if ($parsingResult->getIsConfirmed()) {
                $this->editPartnerOrderService->confirmOrder($order);
                $this->imap->getConnection()->moveMail($this->mail->id, $this->mailBoxConfig['order']['confirm']);
                $this->output->write('Find success order');
            }

            if ($parsingResult->getIsCanceled()) {
                $this->editPartnerOrderService->cancelOrder($order);
                $this->imap->getConnection()->moveMail($this->mail->id, $this->mailBoxConfig['order']['canceled']);
                $this->output->write('Find canceled order');
            }

            if ($parsingResult->getIsChanged()) {
                $this->editPartnerOrderService->changeOrder($order);
                $this->imap->getConnection()->moveMail($this->mail->id, $this->mailBoxConfig['order']['changed']);
                $this->output->write('Find changed order');
            }

            $this->entityManager->persist($order);
            $this->entityManager->flush();
        } catch (OrderHasBeenCanceled $exception) {
            $this->imap->getConnection()->moveMail($this->mail->id, $this->mailBoxConfig['order']['canceled']);
        } catch (OrderByOrderNumberNotFoundException $exception) {
            $this->imap->getConnection()->moveMail($this->mail->id, $this->mailBoxConfig['trash']);
        } catch (OrderItemNotFoundException $exception) {
            $this->imap->getConnection()->moveMail($this->mail->id, $this->mailBoxConfig['trash']);
        }

        return null;
    }
}
