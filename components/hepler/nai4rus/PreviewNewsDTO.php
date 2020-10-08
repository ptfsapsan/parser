<?php

namespace app\components\helper\nai4rus;

use DateTimeInterface;

class PreviewNewsDTO
{
    private string $uri;
    private ?DateTimeInterface $dateTime;
    private ?string $title;
    private ?string $preview;

    public function __construct(
        string $uri,
        ?DateTimeInterface $dateTime = null,
        ?string $title = null,
        ?string $preview = null
    ) {
        $this->uri = $uri;
        $this->dateTime = $dateTime;
        $this->title = $title;
        $this->preview = $preview;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getDateTime(): ?DateTimeInterface
    {
        return $this->dateTime;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getPreview(): ?string
    {
        return $this->preview;
    }
}
