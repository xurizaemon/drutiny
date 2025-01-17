<?php

namespace Drutiny\Report;

interface FilesystemFormatInterface extends FormatInterface
{
    /**
     * Set the writeable directory.
     */
    public function setWriteableDirectory(string $dir):void;

    /**
     * Return the file extension used for the format.
     */
    public function getExtension():string;
}
