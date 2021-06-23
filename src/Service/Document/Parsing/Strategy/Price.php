<?php

namespace App\Service\DocumentParsing\Strategy;

use App\Entity\Brand\Brand;
use App\Entity\Partner\PartnerBrandMarkup;
use App\Entity\Partner\PartnerPrice;
use App\Entity\Partner\PartnerPriceTemplate;
use App\Entity\Partner\PartnerPriceTemplateDefault;
use App\Entity\Setting\Setting;
use App\Entity\Template\Template;
use App\Entity\Template\TemplateField;
use App\Entity\User\UserTemplateFieldValue;
use App\Exception\AttachFileHasErrors;
use App\Repository\Setting\SettingRepository;
use App\Service\DocumentParsing\Entity\PriceParsingResult;
use App\Service\DocumentParsing\ParsingAbstract;
use App\Service\Mail\Validation\Rules\CustomSymbols;
use App\Service\Mail\Validation\Rules\Numeric;
use App\Service\Mail\Validation\Rules\PositiveNumber;
use App\Service\Mail\Validation\Rules\Symbols;
use App\Service\Mail\Validation\Rules\Text;
use App\Service\Price\Entity\PriceItemParsing;
use Doctrine\Common\Collections\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\File\File;
use App\Service\ParsingError\Types\Price as PriceErrorsExport;

class Price extends ParsingAbstract
{
    // Price Parsing
}