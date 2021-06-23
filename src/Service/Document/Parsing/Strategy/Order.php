<?php

namespace App\Service\Document\Parsing\Strategy;

use App\Entity\Order\Order as OrderEntity;
use App\Entity\Order\OrderItem;
use App\Entity\Template\Template;
use App\Entity\Template\TemplateField;
use App\Exception\OrderByOrderNumberNotFoundException;
use App\Exception\OrderItemNotFoundException;
use App\Service\Document\Parsing\ParsingAbstract;
use App\Service\Mail\Validation\Rules\CustomSymbols;
use App\Service\Mail\Validation\Rules\Numeric;
use App\Service\Mail\Validation\Rules\PositiveNumber;
use App\Service\Mail\Validation\Rules\Symbols;
use App\Service\Mail\Validation\Rules\Text;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\File\File;

class Order extends ParsingAbstract
{
    /** @var OrderEntity */
    private $order;

    /** @var Worksheet */
    private $originActiveSheet;

    /**
     * Код шаблона для парсинга
     *
     * @return string
     */
    public static function getTemplateCode(): string
    {
        return Template::CODE_ORDER;
    }

    /**
     * Проверка, что это файл прайса
     *
     * @param File $file
     * @param string $fileName
     * @return bool
     */
    public function isValidFile(File $file, string $fileName): bool
    {
        return preg_match('/(order|заказ)/umsi', $fileName);
    }

    /**
     * Парсим файл
     *
     * @return array|null
     */
    public function parseFile(): ?array
    {
        $activeSheet = $this->spreadSheet->getActiveSheet();
        $orderNumber = $this->getValueCellByFieldCode(
            $activeSheet,
            $this->getUserTemplateFieldValues(),
            TemplateField::CODE_ORDER_NUMBER
        );

        $this->order = $this->getOrder($orderNumber);
        $this->originActiveSheet = $this->getOriginActiveSheet();

        $this->processPendingOrder($activeSheet, $this->getUserTemplateFieldValues());

        return null;
    }

    /**
     * Делаем операции над заказом, который в ожидании
     *
     * @param Worksheet $activeSheet
     * @param $userFields
     */
    public function processPendingOrder(Worksheet $activeSheet, $userFields)
    {
        $this->checkOriginValuesAndReceived($activeSheet, $userFields);

        $this->result->setOrder($this->order);
        $this->result->setRowsCount(1);
    }

    /**
     * Находим наш заказ
     *
     * @param $orderNumber
     *
     * @return OrderEntity
     */
    public function getOrder($orderNumber): OrderEntity
    {
        $orderManager = $this->entityManager->getRepository(OrderEntity::class);

        $order = $orderManager->findOneBy([
            'order_number' => $orderNumber,
        ]);

        if (is_null($order)) {
            throw new OrderByOrderNumberNotFoundException();
        }

        return $order;
    }

    /**
     * Получаем таблицу
     *
     * @return Worksheet
     */
    public function getOriginActiveSheet(): Worksheet
    {
        $spreadSheet = IOFactory::load($this->fileRootPath() . $this->order->getFilePath());
        return $spreadSheet->getActiveSheet();
    }

    /**
     * Проверка отправленных данных на почту и тех, которые пришли от партнера с почты
     *
     * @param $activeSheetReceived
     * @param $userFields
     */
    public function checkOriginValuesAndReceived($activeSheetReceived, $userFields)
    {
        $itemCountUserField = $this->getCellByFieldCode($userFields, TemplateField::CODE_ITEM_ORDER_COUNT);
        $itemPriceUserField = $this->getCellByFieldCode($userFields, TemplateField::CODE_ITEM_TAX_SUM);
        $itemDeliveryDateUserField = $this->getCellByFieldCode($userFields, TemplateField::CODE_ITEM_DELIVERY_PLANNING_DATE);
        $itemOrderNumberUserField = $this->getCellByFieldCode($userFields, TemplateField::CODE_ITEM_NUMBER);

        $confirmedCount = false;
        $canceledCount = false;

        $rowNumber = $itemCountUserField->getRow() + 1;

        $originItemCountValue = $this->originActiveSheet->getCell($itemCountUserField->getColumn() . $rowNumber)->getValue();
        $originItemPriceCellValue = $this->originActiveSheet->getCell($itemPriceUserField->getColumn() . $rowNumber)->getValue();
        $originDeliveryDateCellValue = $this->originActiveSheet->getCell($itemDeliveryDateUserField->getColumn() . $rowNumber)->getValue();
        $itemOrderNumberUserCellValue = $this->originActiveSheet->getCell($itemOrderNumberUserField->getColumn() . $rowNumber)->getValue();

        $receivedItemCountValue = $activeSheetReceived->getCell($itemCountUserField->getColumn() . $rowNumber)->getValue();
        $receivedItemPriceCellValue = (float) $activeSheetReceived->getCell($itemPriceUserField->getColumn() . $rowNumber)->getValue();
        $receivedDeliveryDateCellValue = (int) $activeSheetReceived->getCell($itemDeliveryDateUserField->getColumn() . $rowNumber)->getValue();

        $orderItem = $this->order->getOrderItem();

        if (is_null($orderItem)) {
            throw new OrderItemNotFoundException();
        }

        if ($orderItem->getNumber() != $itemOrderNumberUserCellValue) {
            throw new OrderItemNotFoundException();
        }

        if (is_null($receivedItemCountValue)) {
            throw new OrderItemNotFoundException();
        }

        $orderItem->setCount($receivedItemCountValue)
            ->setPrice($receivedItemPriceCellValue)
            ->setSum($receivedItemCountValue * $receivedItemPriceCellValue)
            ;

        if (
            $originItemCountValue == $receivedItemCountValue &&
            $originItemPriceCellValue == $receivedItemPriceCellValue &&
            $originDeliveryDateCellValue == $receivedDeliveryDateCellValue
        ) {
            $confirmedCount = true;
        }

        if (
            $receivedItemCountValue == 0 &&
            $receivedItemPriceCellValue == 0 &&
            $receivedDeliveryDateCellValue == 0
        ) {
            $canceledCount = true;
        }

        if ($confirmedCount) {
            $this->result->setIsConfirmed(true);
            return;
        }

        if ($canceledCount) {
            $this->result->setIsCanceled(true);
            $orderItem->setPlannedDeliveryDate(new Carbon($receivedDeliveryDateCellValue));
            return;
        }

        $this->result->setIsChanged(true);

        $this->entityManager->persist($orderItem);
        $this->entityManager->flush();
    }

    /**
     * Правила для валидации файла
     * @return array[]
     */
    public function getRules(): array
    {
        return [
            TemplateField::CODE_ITEM_ORDER_COUNT => [
                new Numeric(),
                new Text(),
                new CustomSymbols([
                    '-',
                    '`',
                    's',
                    '/',
                ]),
            ],
            TemplateField::CODE_ITEM_DELIVERY_PLANNING_DATE => [
                new Numeric(),
                new Text(),
                new Symbols(),
            ],
            TemplateField::CODE_MULTIPLICITY => [
                new Numeric()
            ],
            TemplateField::CODE_ITEM_DELIVERY_PLANNING_DATE => [
                new PositiveNumber(),
                new Numeric(),
                new CustomSymbols([
                    '.',
                    ',',
                    '-',
                    '_',
                ]),
            ],
        ];
    }
}