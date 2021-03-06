<?php

namespace M4bTool\Chapter;


use M4bTool\Time\TimeUnit;

interface MetaReaderInterface
{
    public function readFileMetaData(\SplFileInfo $file);

    /**
     * @param \SplFileInfo $file
     * @return TimeUnit
     */
    public function readDuration(\SplFileInfo $file);
}