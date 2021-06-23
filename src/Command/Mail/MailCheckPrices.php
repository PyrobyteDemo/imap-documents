<?php

namespace App\Command\Mail;

/**
 * Проверка на почты новых прайсов
 *
 * Class MailCheckPrices
 * @package App\Command\Mail
 */
class MailCheckPrices extends MailBaseTemplates
{
    /**
     * @param \App\Entity\User\User $user
     * @param ParsingResult $parsingResult
     * @param string $templateCode
     */
    protected function processWithResults(User $user, $parsingResult, string $templateCode)
    {
        // Parsing Price
    }
}
