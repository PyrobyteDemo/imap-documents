<?php

namespace App\Service\Document\Parsing;

use App\Service\DocumentParsing\Entity\PriceParsingResult;
use Symfony\Component\HttpFoundation\File\File;

interface ParsingInterface
{
    public function parseFile():? array;
    public function isValidFile(File $file, string $fileName): bool;
    public static function getTemplateCode(): string;
    public function getResult();
    public function getErrors(): array;
}