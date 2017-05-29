<?php


namespace M4bTool\Command;


use Exception;
use M4bTool\Audio\Chapter;
use M4bTool\Time\TimeUnit;
use SplFileInfo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MergeCommand extends AbstractConversionCommand
{

    const ARGUMENT_MORE_INPUT_FILES = "more-input-files";
    const OPTION_OUTPUT_FILE = "output-file";
    const OPTION_INCLUDE_EXTENSIONS = "include-extensions";

    protected $outputDirectory;

    protected $meta = [];
    /**
     * @var SplFileInfo[]
     */
    protected $files = [];
    protected $sameFormatFiles = [];

    /**
     * @var SplFileInfo
     */
    protected $outputFile;
    protected $sameFormatFileDirectory;

    /**
     * @var Chapter[]
     */
    protected $chapters = [];

    protected function configure()
    {
        parent::configure();

        $this->setDescription('Merges a set of files to one single file');
        $this->setHelp('Merges a set of files to one single file');

        // configure an argument
        $this->addArgument(static::ARGUMENT_MORE_INPUT_FILES, InputArgument::IS_ARRAY, 'Other Input files or folders');
        $this->addOption(static::OPTION_OUTPUT_FILE, null, InputOption::VALUE_REQUIRED, "output file");
        $this->addOption(static::OPTION_INCLUDE_EXTENSIONS, null, InputOption::VALUE_OPTIONAL, "comma separated list of file extensions to include (others are skipped)", "m4b,mp3,aac,mp4,flac");

    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initExecution($input, $output);


        $this->loadInputFiles();

        $this->buildChapters();

        $this->convertFiles();

        $this->importChapters();

        $this->tagMergedFile();
    }

    private function loadInputFiles()
    {
        $includeExtensions = array_filter(explode(',', $this->input->getOption("include-extensions")));

        $this->outputFile = new SplFileInfo($this->input->getOption(static::OPTION_OUTPUT_FILE));
        $this->files = [];
        $this->handleInputFile($this->argInputFile, $includeExtensions);
        $inputFiles = $this->input->getArgument(static::ARGUMENT_MORE_INPUT_FILES);
        foreach ($inputFiles as $fileLink) {
            $this->handleInputFile($fileLink, $includeExtensions);
        }
    }


    protected function handleInputFile($f, $includeExtensions)
    {
        if (!($f instanceof SplFileInfo)) {
            $f = new SplFileInfo($f);
            if (!$f->isReadable()) {
                $this->output->writeln("skipping " . $f . " (does not exist)");
                return;
            }
        }

        if ($f->isDir()) {
            $dir = new \RecursiveDirectoryIterator($f, \FilesystemIterator::SKIP_DOTS);
            $it = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::CHILD_FIRST);
            $filtered = new \CallbackFilterIterator($it, function (SplFileInfo $current /*, $key, $iterator*/) use ($includeExtensions) {
                return in_array($current->getExtension(), $includeExtensions);
            });
            foreach ($filtered as $itFile) {
                if ($itFile->isDir()) {
                    continue;
                }
                if (!$itFile->isReadable() || $itFile->isLink()) {
                    continue;
                }
                $this->files[] = new SplFileInfo($itFile->getRealPath());
            }
        } else {
            $this->files[] = new SplFileInfo($f->getRealPath());
        }
    }

    private function buildChapters()
    {

        if($this->argInputFile->isDir()) {
            $autoCoverFile = new SplFileInfo($this->argInputFile.DIRECTORY_SEPARATOR."cover.jpg");
            if($autoCoverFile->isFile()) {
                $this->setOptionIfUndefined("cover", $autoCoverFile);
            }
        }

        $lastDuration = new TimeUnit();
        foreach ($this->files as $index => $file) {
            $metaData = $this->readFileMetaData($file);

            $this->setOptionIfUndefined("name", $metaData->getProperty("album"));
            $this->setOptionIfUndefined("artist", $metaData->getProperty("artist"));
            $this->setOptionIfUndefined("albumartist", $metaData->getProperty("album_artist"));
            $this->setOptionIfUndefined("year", $metaData->getProperty("date"));
            $this->setOptionIfUndefined("genre", $metaData->getProperty("genre"));
            $this->setOptionIfUndefined("writer", $metaData->getProperty("writer"));
            $this->setOptionIfUndefined("writer", $metaData->getProperty("album_artist"));




            if ($metaData->getDuration()) {
                $start = clone $lastDuration;
                $end = clone $lastDuration;
                $end->add($metaData->getDuration()->milliseconds());

                $title = $metaData->getProperty("title");
                if (!$title) {
                    $title = $index + 1;
                }
                $this->chapters[] = new Chapter($start, $end, $title);
                $lastDuration->add($metaData->getDuration()->milliseconds());
            }

        }
    }

    private function setOptionIfUndefined($optionName, $optionValue)
    {
        if (!$this->input->getOption($optionName) && $optionValue) {
            $this->input->setOption($optionName, $optionValue);
        }
    }

    private function convertFiles()
    {


        // ffmpeg -i input1.mp4 -i input2.webm -filter_complex "[0:v:0] [0:a:0] [1:v:0] [1:a:0] concat=n=2:v=1:a=1 [v] [a]" -map "[v]" -map "[a]" <encoding options> output.mkv

        $command = [
            "ffmpeg",
            "-vn",
        ];

        $filterComplex = "";
        $index = 0;
        foreach ($this->files as $index => $file) {
            $command[] = "-i";
            $command[] = $file;

            $filterComplex .= "[" . $index . ":0] ";
        }
        $filterComplex .= "concat=n=" . ($index + 1) . ":v=0:a=1 [a]";

        $this->appendParameterToCommand($command, "-filter_complex", $filterComplex);
        $command[] = "-map";
        $command[] = "[a]";


        $this->appendParameterToCommand($command, "-y", $this->optForce);
        $this->appendParameterToCommand($command, "-ab", $this->optAudioBitRate);
        $this->appendParameterToCommand($command, "-ar", $this->optAudioSampleRate);
        $this->appendParameterToCommand($command, "-ac", $this->optAudioChannels);
        $this->appendParameterToCommand($command, "-acodec", $this->optAudioCodec);
        $this->appendParameterToCommand($command, "-f", $this->optAudioFormat);
        $command[] = $this->outputFile;

        $this->shell($command, "merging " . count($this->files) . " files into target " . $this->outputFile . ", this can take a while");
    }

    private function importChapters()
    {

        if (count($this->chapters) == 0) {
            return;
        }

        if ($this->optAudioFormat != "mp4") {
            return;
        }
        $chaptersFile = $this->audioFileToChaptersFile($this->outputFile);
        if ($chaptersFile->isFile() && !$this->optForce) {
            throw new Exception("Chapters file " . $chaptersFile . " already exists, use --force to force overwrite");
        }

        file_put_contents($chaptersFile, implode(PHP_EOL, $this->chaptersAsLines()));
        $this->shell(["mp4chaps", "-i", $this->outputFile], "importing chapters for " . $this->outputFile);
    }

    private function chaptersAsLines()
    {
        $chaptersAsLines = [];
        foreach ($this->chapters as $chapter) {
            $chaptersAsLines[] = $chapter->getStart()->format("%H:%I:%S.%V") . " " . $chapter->getName();
        }
        return $chaptersAsLines;
    }

    private function tagMergedFile()
    {
        $tag = $this->inputOptionsToTag();
        $this->tagFile($this->outputFile, $tag);
    }
}