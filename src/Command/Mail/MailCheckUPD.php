<?php

namespace App\Command\Mail;

/**
 * Проверка на почте новых UPD
 *
 * Class MailCheckUPD
 * @package App\Command\Mail
 */
class MailCheckUPD extends MailBaseTemplates
{
    protected static $defaultName = 'mail:check:upd';
    protected $templateCode = Template::CODE_UPD;

    /**
     * @param \App\Entity\User\User $user
     * @param ParsingResult $parsingResult
     * @param string $templateCode
     */
    protected function processWithResults(\App\Entity\User\User $user, $parsingResult, string $templateCode)
    {
        // Parsing UPD
    }
}
