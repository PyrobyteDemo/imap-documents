<?php

namespace App\Command\Mail;

use App\Entity\Partner\Partner;
use App\Entity\Notification\Notification;
use App\Entity\Template\Template;
use App\Entity\User\User;
use App\Exception\AttachFileHasErrors;
use App\Exception\CheckFileType;
use App\Exception\MailAttachFileNotFound;
use App\Exception\MailAttachFileStrategyNotFound;
use App\Exception\OrderByOrderNumberNotFoundException;
use App\Service\AMQP\RabbitMQ\RabbitMq;
use App\Service\Export\Xls\ExportAbstract;
use App\Service\Imap;
use App\Service\Mail\Handler\MailHandler;
use App\Service\Mailer\MailerService;
use App\Service\Mailer\Mails\NotSupportAttachExtension;
use App\Service\Mailer\Mails\ParsingDocumentError;
use App\Service\Notification\CreateNotificationService;
use App\Service\ParsingError\Types\Price;
use Doctrine\ORM\EntityManagerInterface;
use PhpImap\IncomingMail;
use PhpOffice\PhpSpreadsheet\Reader\Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Базовый класс обратботки шаблонов
 *
 * Class MailBaseTemplates
 * @package App\Command\Mail
 */
class MailBaseTemplates extends Command
{
    protected static $defaultName = 'mail:check';

    /** @var Imap */
    protected $imap;
    protected $mailHandler;
    protected $entityManager;
    protected $templateCode;

    /** @var IncomingMail */
    protected $mail;

    /** @var RabbitMq */
    protected $rabbitMq;

    /** @var MailerService */
    protected $mailerService;

    /** @var LoggerInterface */
    private $logger;

    /** @var mixed */
    protected $mailBoxConfig;

    /** @var string */
    protected $errorsFile;

    /** @var Filesystem */
    protected $fileSystem;

    protected $createNotificationService;

    /** @var OutputInterface */
    protected $output;

    public function __construct(
        Imap $imap,
        MailHandler $mailHandler,
        EntityManagerInterface $entityManager,
        MailerService $mailerService,
        RabbitMq $rabbitMq,
        LoggerInterface $logger,
        ParameterBagInterface $parameterBag,
        Filesystem $fileSystem,
        CreateNotificationService $createNotificationService
    )
    {
        $this->imap = $imap;
        $this->mailHandler = $mailHandler;
        $this->entityManager = $entityManager;
        $this->mailerService = $mailerService;
        $this->rabbitMq = $rabbitMq;
        $this->logger = $logger;
        $this->mailBoxConfig = $parameterBag->get('mailbox');
        $this->fileSystem = $fileSystem;
        $this->createNotificationService = $createNotificationService;

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output):? int
    {
        $this->output = $output;
        $io = new SymfonyStyle($input, $output);

        $this->output->write('Был взят шаблон ' . $this->templateCode);

        if (!in_array($this->templateCode, Template::CODES)) {
            $io->error('Такого типа шаблона не существует.');
            return Command::FAILURE;
        }

        $partnerRepository = $this->entityManager->getRepository(Partner::class);

        $partners = $partnerRepository->findBy(['status' => Partner::STATUS_ACTIVE]);

        foreach ($partners as $partner) {
            $user = $partner->getUser();
            $mailIds = $this->imap->getByEmail($user->getEmail());

            if (empty($mailIds)) {
                continue;
            }

            foreach ($mailIds as $keyMail => $mailId) {
                $mail = $this->imap->getMailById($mailId);
                $isFileValid = true;

                if (empty($mail)) {
                    continue;
                }

                try {
                    $this->mailHandler->processDocument($mail, $user->getEmail(), $this->templateCode);
                } catch (AttachFileHasErrors $exception) {
                    $this->mailerService->send(new ParsingDocumentError(
                        $user->getEmail(),
                        $exception->getAndSaveErrorsAsString(),
                        $exception->getFileName()
                    ));

                    if ($this->templateCode == Template::CODE_UPD) {
                        $this->createNotificationService
                            ->setType(Notification::TYPE_ERROR)
                            ->setTitle('Ошибка при загрузке УПД')
                            ->setDescription('Ошибка при загрузке УПД')
                            ->sendNotification($user, true);
                    }

                    if ($this->templateCode == Template::CODE_PRICE) {
                        $this->createNotificationService
                            ->setType(Notification::TYPE_ERROR)
                            ->setTitle('Ошибка при загрузке Прайс листа')
                            ->setDescription('Наименование прайса: ' . $exception->getFileName())
                            ->sendNotification($user, true);
                    }

                    $this->imap->getConnection()->moveMail($mail->id, $this->mailBoxConfig[$this->templateCode]['validationError']);
                    continue;
                } catch (CheckFileType $exception) {
                    $isFileValid = false;
                    continue;
                } catch (Exception $exception) {
                    $this->mailerService->send(new NotSupportAttachExtension($user->getEmail()));
                    $this->imap->getConnection()->moveMail($mail->id, $this->mailBoxConfig['trash']);
                    continue;
                } catch (MailAttachFileNotFound $exception) {
                    $this->imap->getConnection()->moveMail($mail->id, $this->mailBoxConfig['trash']);
                    continue;
                } catch (OrderByOrderNumberNotFoundException $exception) {
                    $this->imap->getConnection()->moveMail($mail->id, $this->mailBoxConfig['trash']);
                    continue;
                } catch (\Exception $exception) {
                    $this->logger->error($exception->getTraceAsString());
                    continue;
                } finally {
                    if (!$isFileValid) {
                        return Command::SUCCESS;
                    }

                    $strategy = $this->mailHandler->getStrategy();

                    if (empty($strategy)) {
                        return Command::SUCCESS;
                    }

                    $this->mail = $mail;

                    if (!empty($strategy->getErrors()) && $this->templateCode != Template::CODE_PRICE) {
                        return Command::SUCCESS;
                    }

                    if ($this->templateCode != Template::CODE_ORDER) {
                        $this->imap->getConnection()->moveMail($mail->id, $this->mailBoxConfig[$this->templateCode]['approve']);
                    }

                    $this->processWithResults($user, $strategy->getResult(), $this->templateCode);
                }
            }

            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }

    /**
     * После разбора шаблона что-то делаем с их содержимым или результатом разбора
     *
     * @param User $user
     * @param string $templateCode
     */
    abstract protected function processWithResults(User $user, $parsingResult, string $templateCode);

    public function getMailBoxConfig()
    {
        return $this->mailBoxConfig;
    }

    /**
     * @param mixed $mailBoxConfig
     * @return self
     */
    public function setMailBoxConfig($mailBoxConfig): self
    {
        $this->mailBoxConfig = $mailBoxConfig;
        return $this;
    }
}