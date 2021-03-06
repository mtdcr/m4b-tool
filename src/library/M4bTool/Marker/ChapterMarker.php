<?php


namespace M4bTool\Marker;


use M4bTool\Audio\Chapter;
use M4bTool\Audio\Silence;
use M4bTool\Time\TimeUnit;

class ChapterMarker
{
    protected $debug = false;
    protected $maxDiffMilliseconds = 25000;

    public function __construct($debug = false)
    {
        $this->debug = $debug;
    }

    public function setMaxDiffMilliseconds($maxDiffMilliseconds)
    {
        $this->maxDiffMilliseconds = $maxDiffMilliseconds;
    }

    public function guessChaptersBySilences($mbChapters, $silences, TimeUnit $fullLength)
    {
        $guessedChapters = [];
        $chapterOffset = new TimeUnit();

        $silences = $this->normalizeSilenceArray($silences);
        /**
         * @var Chapter $chapter
         */
        foreach ($mbChapters as $chapter) {

            $this->debug("chapter: " . $chapter->getStart()->format("%H:%I:%S.%V"));

            $chapterStart = $chapter->getStart()->milliseconds();
            if ($chapterStart == 0) {
                $guessedChapters[$chapterStart] = new Chapter(new TimeUnit($chapterStart), new TimeUnit(), $chapter->getName());
                $this->debug(", no silence" . PHP_EOL);
                continue;
            }


            $index = 0;
            $bestMatchSilenceIndex = 0;
            $bestMatchSilenceKey = null;
            $bestMatchSilenceDiff = null;
            /**
             * @var Silence[] $silences
             */
            foreach ($silences as $silence) {
                $silenceStart = $silence->getStart()->milliseconds();
                $diff = abs($chapterStart - $chapterOffset->milliseconds() - $silenceStart);
                if ($bestMatchSilenceKey == null || $bestMatchSilenceDiff == null || min($diff, $bestMatchSilenceDiff) == $diff) {
                    $bestMatchSilenceKey = $silenceStart;
                    $bestMatchSilenceDiff = $diff;
                    $bestMatchSilenceIndex = $index;
                }
                $index++;
            }

            $nextOffsetMilliseconds = $chapterStart - $bestMatchSilenceKey;
            if (abs($nextOffsetMilliseconds - $chapterOffset->milliseconds()) < $this->maxDiffMilliseconds) {
                $chapterOffset = new TimeUnit($chapterStart - $bestMatchSilenceKey);
                $chapterSilenceMatchFound = true;
            } else {
                // no matching silence for chapter
                // set chapter mark exactly where it is
                $chapterSilenceMatchFound = false;
            }


            $start = min(max(0, $bestMatchSilenceIndex - 1), count($silences) - 1);
            $length = 3;
            if ($start == 0) {
                $length = 2;
            } else if ($start == count($silences) - 1) {
                $start--;
                $length = 2;
            }

            /**
             * @var Silence[] $potentialSilences
             */
            $potentialSilences = array_slice($silences, $start, $length, true);

            $index = 0;
            foreach ($potentialSilences as $silence) {

                $silenceStart = $silence->getStart()->milliseconds();
                $marker = "-";
                if ($silenceStart == $bestMatchSilenceKey) {
                    $marker = "+";
                }
                if ($index++ == 0) {
                    $this->debug(", silence: " . $marker . $silence->getStart()->format("%H:%I:%S.%V") . ", duration: " . $silence->getLength()->format("%H:%I:%S.%V") . PHP_EOL);
                } else {
                    $this->debug("                                " . $marker . $silence->getStart()->format("%H:%I:%S.%V") . ", duration: " . $silence->getLength()->format("%H:%I:%S.%V") . PHP_EOL);
                }
            }


            if ($chapterSilenceMatchFound && isset($silences[$bestMatchSilenceKey])) {
                $silences[$bestMatchSilenceKey]->setChapterStart(true);
                $chapterMark = $silences[$bestMatchSilenceKey]->getStart();
                $chapterMark->add($silences[$bestMatchSilenceKey]->getLength()->milliseconds() / 2);
            } else {
                $chapterMark = $chapter->getStart();
                $chapterMark->add($chapterOffset->milliseconds());
            }

            $guessedChapters[$chapterMark->milliseconds()] = new Chapter($chapterMark, new TimeUnit(), $chapter->getName());

            $this->debug($chapter->getName() . " - chapter-offset: " . $chapterOffset->format("%H:%I:%S.%V") . PHP_EOL);
            $this->debug("chapter-mark: " . $chapterMark->format("%H:%I:%S.%V") . PHP_EOL);
            $this->debug("=======================================================================" . PHP_EOL);

//            file_put_contents("../data/src-import.chapters.txt", $chapterMark->format("%H:%I:%S.%V") . " ".$chapter->getName().PHP_EOL, FILE_APPEND);

        }


        $lastStart = null;
        foreach ($guessedChapters as $chapter) {
            $start = $chapter->getStart()->milliseconds();
            if ($lastStart !== null && isset($guessedChapters[$lastStart])) {
                $guessedChapters[$lastStart]->setLength(new TimeUnit($start - $lastStart));
            }
            $lastStart = $start;
        }

        if (count($guessedChapters) > 0) {
            $lastGuessedChapter = end($guessedChapters);
            $lastGuessedChapter->setLength(new TimeUnit($fullLength->milliseconds() - $lastGuessedChapter->getStart()->milliseconds()));
        } else {
            $lastSilence = new Silence(new TimeUnit(), new TimeUnit(5, TimeUnit::SECOND));
            $lastChapter = null;
            $index = 1;
            foreach($silences as $silence) {
                if($silence->getStart()->milliseconds()  < $lastSilence->getEnd()->milliseconds()) {
                    continue;
                }

                if($lastChapter instanceof Chapter && $lastChapter->getStart()->milliseconds() + 60000 > $silence->getStart()->milliseconds() ) {
                    continue;
                }

                $lastChapter = new Chapter($lastSilence->getStart(), new TimeUnit($silence->getStart()->milliseconds() + ($silence->getLength()->milliseconds() / 2)), $index);
                $guessedChapters[] = $lastChapter;
                $index++;
                $lastSilence = $silence;
            }

            if($lastChapter && !in_array($lastChapter, $guessedChapters, true)) {
                $guessedChapters[] = $lastChapter;
            }
        }


        return $guessedChapters;
    }

    /**
     * @param array Silence[]
     * @return array Silence[]
     */
    private function normalizeSilenceArray(array $silences)
    {
        $normSilences = [];
        foreach ($silences as $silence) {
            $normSilences[$silence->getStart()->milliseconds()] = $silence;
        }
        return $normSilences;
    }

    public function debug($message)
    {
        if ($this->debug) {
            echo $message;
        }
    }

    /**
     *
     * @param Chapter[] $mbChapters
     * @param Chapter[] $trackChapters
     * @return Chapter[] $guessedChapters
     */
    public function guessChaptersByTracks($mbChapters, $trackChapters)
    {


        $guessedChapters = [];
        $index = 1;
        foreach ($trackChapters as $key => $trackChapter) {
            $chapter = clone $trackChapter;

            $this->debug("track " . ($index) . ": " . $chapter->getStart()->format("%H:%I:%S.%V") . " - " . $chapter->getEnd()->format("%H:%I:%S.%V") . " (" . $chapter->getStart()->milliseconds() . "-" . $chapter->getEnd()->milliseconds() . ", " . $chapter->getName() . ")");

            reset($mbChapters);
            $bestMatchChapter = current($mbChapters);

            $chapterStartMillis = $chapter->getStart()->milliseconds();
            $chapterEndMillis = $chapter->getEnd()->milliseconds();
            foreach ($mbChapters as $mbChapter) {
                $mbStart = max($chapterStartMillis, $mbChapter->getStart()->milliseconds());
                $mbEnd = min($chapterEndMillis, $mbChapter->getEnd()->milliseconds());
                $mbOverlap = $mbEnd - $mbStart;

                $bestMatchStart = max($chapterStartMillis, $bestMatchChapter->getStart()->milliseconds());
                $bestMatchEnd = min($chapterEndMillis, $bestMatchChapter->getEnd()->milliseconds());
                $bestMatchOverlap = $bestMatchEnd - $bestMatchStart;

                if ($mbChapter === $bestMatchChapter || $mbOverlap > $bestMatchOverlap) {
                    $this->debug("   +" . $mbChapter->getStart()->format("%H:%I:%S.%V") . " - " . $mbChapter->getEnd()->format("%H:%I:%S.%V") . " (" . $mbChapter->getStart()->milliseconds() . "-" . $mbChapter->getEnd()->milliseconds() . ", " . $mbChapter->getName() . ")");
                    $bestMatchChapter = $mbChapter;
                } else {
                    $this->debug("   -" . $mbChapter->getStart()->format("%H:%I:%S.%V") . " - " . $mbChapter->getEnd()->format("%H:%I:%S.%V") . " (" . $mbChapter->getStart()->milliseconds() . "-" . $mbChapter->getEnd()->milliseconds() . ", " . $mbChapter->getName() . ")");
                }
            }

            $chapter->setName($bestMatchChapter->getName());

            $guessedChapters[$key] = $chapter;
            $index++;
        }
        return $guessedChapters;


    }

    /**
     * @param Chapter[] $chapters
     */
    public function normalizeChapters($chapters, $options)
    {

        $defaults = [
            'first-chapter-offset' => 0,
            'last-chapter-offset' => 0,
            'merge-similar' => false,
            'no-chapter-numbering' => false,
            'chapter-pattern' => "/^[^:]+[1-9][0-9]*:[\s]*(.*),.*[1-9][0-9]*[\s]*$/i",
            'chapter-remove-chars' => "„“”",
        ];

        $options = array_merge($defaults, $options);


        $chaptersAsLines = [];
        $index = 0;
        $chapterIndex = 1;
        $lastChapterName = "";
        $firstChapterOffset = (int)$options['first-chapter-offset'];
        $lastChapterOffset = (int)$options['last-chapter-offset'];


        if ($firstChapterOffset) {
            $firstOffset = new TimeUnit(0, TimeUnit::MILLISECOND);
            $chaptersAsLines[] = new Chapter($firstOffset, new TimeUnit(0, TimeUnit::MILLISECOND), "Offset First Chapter");
        }
        foreach ($chapters as $chapter) {
            $index++;
            $replacedChapterName = $this->replaceChapterName($chapter->getName(), $options['chapter-pattern'], $options['chapter-remove-chars']);
            $suffix = "";

            if ($lastChapterName != $replacedChapterName) {
                $chapterIndex = 1;
            } else {
                $chapterIndex++;
            }
            if ($options["merge-similar"]) {
                if ($chapterIndex > 1) {
                    continue;
                }
            } else if (!$options["no-chapter-numbering"]) {
                $suffix = " (" . $chapterIndex . ")";
            }
            /**
             * @var TimeUnit $start
             */
            $start = $chapter->getStart();
            if ($index === 1 && $firstChapterOffset) {
                $start->add($firstChapterOffset, TimeUnit::MILLISECOND);
            }

            $newChapter = clone $chapter;
            $newChapter->setStart($start);
            $newChapter->setName($replacedChapterName . $suffix);
            $chaptersAsLines[$newChapter->getStart()->milliseconds()] = $newChapter;
            $lastChapterName = $replacedChapterName;
        }

        if ($lastChapterOffset && isset($chapter)) {
            $offsetChapterStart = new TimeUnit($chapter->getEnd()->milliseconds() - $lastChapterOffset, TimeUnit::MILLISECOND);
            $chaptersAsLines[$offsetChapterStart->milliseconds()] = new Chapter($offsetChapterStart, new TimeUnit(0, TimeUnit::MILLISECOND), "Offset Last Chapter");
        }

        return $chaptersAsLines;


    }

    private function replaceChapterName($chapter, $chapterPattern, $removeCharsParameter)
    {
        $chapterName = preg_replace($chapterPattern, "$1", $chapter);

        // utf-8 aware char replacement
        $removeChars = preg_split('//u', $removeCharsParameter, null, PREG_SPLIT_NO_EMPTY);
        $presentChars = preg_split('//u', $chapterName, null, PREG_SPLIT_NO_EMPTY);
        $replacedChars = array_diff($presentChars, $removeChars);
        return implode("", $replacedChars);
    }

//    private function parseSpecialOffsetChaptersOption($misplacedChapters)
//    {
//        $tmp = explode(',', $misplacedChapters);
//        $specialOffsetChapters = [];
//        foreach ($tmp as $key => $value) {
//            $chapterNumber = trim($value);
//            if (is_numeric($chapterNumber)) {
//                $specialOffsetChapters[] = (int)$chapterNumber;
//            }
//        }
//        return $specialOffsetChapters;
//    }
}