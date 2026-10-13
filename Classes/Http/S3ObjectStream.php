<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Http;

use AsyncAws\S3\Result\GetObjectOutput;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;

/**
 * A PSR-7 body over an S3 GetObject response. Forward-only: emit() streams
 * the chunks straight to the output buffer, read() serves them to callers
 * that insist on pulling. Nothing is buffered beyond one chunk.
 */
final class S3ObjectStream implements SelfEmittableStreamInterface
{
    /** @var \Iterator<string>|null */
    private ?\Iterator $chunks = null;
    private string $buffer = '';
    private bool $finished = false;
    private int $position = 0;

    public function __construct(
        private readonly GetObjectOutput $output,
        private readonly ?int $size,
    ) {}

    public function emit(): void
    {
        if ($this->buffer !== '') {
            echo $this->buffer;
            $this->position += strlen($this->buffer);
            $this->buffer = '';
        }
        $iterator = $this->iterator();
        while ($iterator->valid()) {
            $chunk = (string)$iterator->current();
            echo $chunk;
            $this->position += strlen($chunk);
            $iterator->next();
        }
        $this->finished = true;
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->finished = true;
        $this->buffer = '';
    }

    public function detach(): null
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->finished && $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('S3 object streams are not seekable.', 1760400070);
    }

    public function rewind(): void
    {
        throw new \RuntimeException('S3 object streams cannot be rewound.', 1760400071);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('S3 object streams are read-only.', 1760400072);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $iterator = $this->iterator();
        while (strlen($this->buffer) < $length && $iterator->valid()) {
            $this->buffer .= (string)$iterator->current();
            $iterator->next();
        }
        if (!$iterator->valid()) {
            $this->finished = true;
        }
        $out = substr($this->buffer, 0, $length);
        $this->buffer = (string)substr($this->buffer, $length);
        $this->position += strlen($out);
        return $out;
    }

    public function getContents(): string
    {
        $contents = $this->buffer;
        $this->buffer = '';
        // Never foreach here: a generator that read() already advanced cannot be rewound.
        $iterator = $this->iterator();
        while ($iterator->valid()) {
            $contents .= (string)$iterator->current();
            $iterator->next();
        }
        $this->finished = true;
        $this->position += strlen($contents);
        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * @return \Iterator<string>
     */
    private function iterator(): \Iterator
    {
        if ($this->chunks === null) {
            $chunks = $this->output->getBody()->getChunks();
            $this->chunks = $chunks instanceof \Iterator ? $chunks : new \ArrayIterator(is_array($chunks) ? $chunks : iterator_to_array($chunks, false));
            $this->chunks->rewind();
        }
        return $this->chunks;
    }
}
