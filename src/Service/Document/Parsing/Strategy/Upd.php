<?php


namespace App\Service\DocumentParsing\Strategy;


use App\Entity\Partner\PartnerUpdTemplate;
use App\Entity\Partner\PartnerUpdTemplateDefault;
use App\Entity\Template\Template;
use App\Entity\Template\TemplateField;
use App\Entity\User\UserTemplateFieldValue;
use App\Exception\AttachFileHasErrors;
use App\Service\DocumentParsing\ParsingAbstract;
use App\Service\Mail\Validation\Rules\Basic;
use App\Service\Mail\Validation\Rules\CustomSymbols;
use App\Service\Mail\Validation\Rules\Numeric;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\HttpFoundation\File\File;

class Upd extends ParsingAbstract
{
    // UPD parsing
}