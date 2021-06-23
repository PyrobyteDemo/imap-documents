<?php

namespace App\Service\Document\Parsing\Strategy;

class ParsingResult
{
    private $order;
    private $rowsCount;
    private $isCanceled;
    private $isConfirmed;
    private $isChanged;

    /**
     * @return mixed
     */
    public function getOrder()
    {
        return $this->order;
    }

    /**
     * @return mixed
     */
    public function getRowsCount()
    {
        return $this->rowsCount;
    }

    /**
     * @return mixed
     */
    public function getIsCanceled()
    {
        return $this->isCanceled;
    }

    /**
     * @return mixed
     */
    public function getIsConfirmed()
    {
        return $this->isConfirmed;
    }

    /**
     * @return mixed
     */
    public function getIsChanged()
    {
        return $this->isChanged;
    }

    /**
     * @param mixed $order
     * @return self
     */
    public function setOrder($order): self
    {
        $this->order = $order;
        return $this;
    }

    /**
     * @param mixed $rowsCount
     * @return self
     */
    public function setRowsCount($rowsCount): self
    {
        $this->rowsCount = $rowsCount;
        return $this;
    }

    /**
     * @param mixed $isCanceled
     * @return self
     */
    public function setIsCanceled($isCanceled): self
    {
        $this->isCanceled = $isCanceled;
        return $this;
    }

    /**
     * @param mixed $isConfirmed
     * @return self
     */
    public function setIsConfirmed($isConfirmed): self
    {
        $this->isConfirmed = $isConfirmed;
        return $this;
    }

    /**
     * @param mixed $isChanged
     * @return self
     */
    public function setIsChanged($isChanged): self
    {
        $this->isChanged = $isChanged;
        return $this;
    }
}
