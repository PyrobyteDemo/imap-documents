<?php


namespace App\Service\Document\Parsing;


use App\Entity\ParseFile;
use App\Entity\Template\Template;
use App\Entity\Template\TemplateField;
use App\Entity\User\User;
use App\Entity\User\UserTemplate;
use App\Entity\User\UserTemplateFieldValue;
use App\Exception\CheckFileType;
use App\Service\Document\Parsing\Strategy\ParsingResult;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

abstract class ParsingAbstract implements ParsingInterface
{
    protected ParsingResult $result;
    /** @var array */
    protected $errors = [];
    /** @var Spreadsheet */
    protected $spreadSheet;
    /** @var EntityManagerInterface */
    protected $entityManager;
    /** @var ParseFile $parseFileEntity */
    protected $parseFileEntity;
    /** @var User */
    protected $user;
    /** @var Template $template */
    protected $template;
    /** @var UserTemplate $userTemplate */
    protected $userTemplate;
    /** @var Filesystem */
    protected $fileSystem;
    /** @var ParameterBagInterface  */
    protected $parameterBag;

    protected $fileRootPath = '';

    public function __construct
    (
        EntityManagerInterface $entityManager,
        User $user,
        Filesystem $fileSystem,
        ParameterBagInterface $parameterBag
    ) {
        $this->entityManager = $entityManager;
        $this->user = $user;
        $this->fileSystem = $fileSystem;
        $this->parameterBag = $parameterBag;

        $this->template = $entityManager->getRepository(Template::class)
            ->findOneBy([
                'code' => $this->getTemplateCode(),
            ]);

        $this->userTemplate = $entityManager->getRepository(UserTemplate::class)
            ->findOneBy([
                'template_id' => $this->template->getId(),
                'user_id' => $this->user->getId(),
            ]);
    }

    /**
     * @return User
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * Получаем результат
     */
    public function getResult(): ParsingResult
    {
        return $this->result;
    }

    /**
     * @param ParsingResult $result
     */
    public function setResult(ParsingResult $result): void
    {
        $this->result = $result;
    }

    /**
     * Загружаем файл
     *
     * @param File $file
     *
     * @param string $fileName
     * @return $this
     * @throws CheckFileType
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function loadFile(File $file, string $fileName): self
    {
        $this->spreadSheet = IOFactory::load($file->getRealPath());

        if (!$this->isValidFile($file, $fileName)) {
            throw new CheckFileType('File type not valid');
        }

        $this->createParseFile($file, $fileName);

        return $this;
    }

    /**
     * Проверяем файл на валидность
     *
     * @param File $file
     * @param string $fileName
     * @return bool
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function isValidFile(File $file, string $fileName): bool
    {
        $userFields = $this->userTemplate->getUserTemplateFieldValues();

        if (empty($userFields) || $userFields->count() == 0) {
            return false;
        }

        /** @var UserTemplateFieldValue $userField */
        foreach ($userFields as $userField) {
            $valueCell = $this->spreadSheet
                ->getActiveSheet()
                ->getCell($userField->getColumn() . $userField->getRow())
                ->getValue();

            $valueCell = str_replace("\n", " ", $valueCell);

            if (trim($valueCell) != $userField->getField()->getName()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param File $file
     * @param string $fileName
     */
    protected function createParseFile(File $file, string $fileName): void
    {
        $parseFileEntity = (new ParseFile())->setUserId($this->getUser()->getId())
            ->setType($this->getTemplateCode())
            ->setFilename($fileName)
            ->setPath($file->getRealPath())
            ->setCreatedAt(new \DateTime())
            ->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($parseFileEntity);
        $this->entityManager->flush();

        $this->parseFileEntity = $parseFileEntity;
    }

    /**
     * Записываем ошибки при парсинге файлов
     *
     * @param string|null $cellValue
     * @param UserTemplateFieldValue $userField
     * @param int $rowNumber
     * @param string $columnNumber
     */
    public function validateCell
    (
        UserTemplateFieldValue $userField,
        int $rowNumber,
        string $columnNumber,
        string $cellValue = null
    )
    {
        $rules = $this->getRules();
        $userFieldCode = $userField->getField()->getCode();

        $defaultCellValue = $cellValue;
        $defaultErrorMessage = 'Ячейка ' . $columnNumber;

        if (empty($rules[$userFieldCode])) {
            return;
        }

        $rulesByCode = $rules[$userFieldCode];

        if (!isset($cellValue)) {
            $this->errors[$rowNumber][$columnNumber] = [
                'firstRowNumber' => $rowNumber,
                'lastRowNumber' => $rowNumber,
                'text' => $defaultErrorMessage . $rowNumber . ' - ошибка заполнения. Пустое поле.',
                'error_text' => 'Ошибка заполнения. Пустое поле',
            ];
            return;
        }

        foreach ($rulesByCode as $key => $rule) {
            if (empty($rule)) {
                $cellValue = null;
                break;
            }

            if (method_exists($rule, 'getMessage')) {
                if (!$rule->validate($defaultCellValue)) {
                    $this->errors[$rowNumber][$columnNumber] = [
                        'firstRowNumber' => $rowNumber,
                        'lastRowNumber' => $rowNumber,
                        'text' => $defaultErrorMessage . $rowNumber . ' ' . $rule->getMessage(),
                        'error_text' => $rule->getMessage(),
                    ];
                }
            }

            $cellValue = $rule->validate($cellValue);
        }

        if (!empty($cellValue)) {
            if (empty($this->errors[$rowNumber])) {
                $this->errors[$rowNumber][$columnNumber] = [
                    'firstRowNumber' => $rowNumber,
                    'lastRowNumber' => $rowNumber,
                    'text' => $defaultErrorMessage . $rowNumber . ' - ошибка заполнения.',
                    'error_text' => 'Ошибка заполнения',
                ];
                return;
            }

            if (isset($this->errors[$rowNumber - 1][$columnNumber])) {
                $prevError = $this->errors[$rowNumber - 1][$columnNumber];

                $this->errors[$rowNumber][$columnNumber] = [
                    'firstRowNumber' => $prevError['firstRowNumber'],
                    'lastRowNumber' => $rowNumber,
                    'text' => $defaultErrorMessage . $prevError['firstRowNumber'] . '-' . $rowNumber . ' - ошибка заполнения.',
                    'error_text' => 'Ошибка заполнения',
                ];
                unset($this->errors[$rowNumber - 1][$columnNumber]);
                return;
            }

            $this->errors[$rowNumber][$columnNumber] = [
                'firstRowNumber' => $rowNumber,
                'lastRowNumber' => $rowNumber,
                'text' => $defaultErrorMessage . $rowNumber . ' - ошибка заполнения.',
                'error_text' => 'Ошибка заполнения'
            ];
        }
    }

    /**
     * Получить значение ячейкии по её коду
     *
     * @param        $activeSheet
     * @param        $userFields
     * @param string $fieldCode
     *
     * @return mixed
     */
    public function getValueCellByFieldCode($activeSheet, $userFields, string $fieldCode)
    {
        foreach ($userFields as $userField) {
            if ($fieldCode != $userField->getField()->getCode()) {
                continue;
            }

            return $activeSheet->getCell($userField->getColumnValue() . $userField->getRowValue())
                ->getValue();
        }
    }

    /**
     * Получить ячейку по её коду
     *
     * @param        $userFields
     * @param string $fieldCode
     *
     * @return UserTemplateFieldValue
     */
    public function getCellByFieldCode($userFields, string $fieldCode): UserTemplateFieldValue
    {
        foreach ($userFields as $userField) {
            if ($fieldCode != $userField->getField()->getCode()) {
                continue;
            }

            return $userField;
        }
    }

    /**
     * @return Collection
     */
    public function getUserTemplateFieldValues(): Collection
    {
        return $this->userTemplate->getUserTemplateFieldValues();
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->fileRootPath = $config['fileRootPath'] ?? dirname(__DIR__, 4) . '/public';
    }

    /**
     * @return string
     */
    public function fileRootPath(): string
    {
        return $this->fileRootPath;
    }
}