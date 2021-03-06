<?php
namespace Yuav\RestEncoderBundle\Processor;

use Yuav\RestEncoderBundle\Entity\Job;
use Yuav\RestEncoderBundle\Entity\MediaFile;
use Yuav\RestEncoderBundle\Entity\Output;
use Yuav\RestEncoderBundle\Processor\Job\OutputFilter;
use Yuav\RestEncoderBundle\Processor\Input\Downloader;
use Doctrine\Common\Persistence\ObjectManager;
use Monolog;
use OldSound\RabbitMqBundle\RabbitMq\Producer;
use Psr\Log\LoggerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Yuav\RestEncoderBundle\Processor\Job\OutputTranslator;
use Yuav\RestEncoderBundle\Entity\Input;

class JobProcessor
{

    private $logger;

    private $om;

    private $outputQueueProducer;

    private $inputBeingDownloaded;

    private $inputBeingDownloadedLastUpdate;

    private $mediaFileProcessor;

    private $tempFiles = array();

    private $outputFilter;

    private $downloader;

    public function __construct(ObjectManager $om, Producer $outputQueueProducer, LoggerInterface $logger = null)
    {
        $this->om = $om;
        $this->outputQueueProducer = $outputQueueProducer;
        $this->logger = $logger;
    }

    public function __destruct()
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function process(Job $job)
    {
        $input = $job->getInput();
        if (! $input instanceof Input) {
            throw new \InvalidArgumentException('Invalid or empty input set in job');
        }
        
        // Download a local copy of input
        if ($this->logger) {
            $this->logger->debug('Downloading file ' . $input->getUri());
        }
        
        // TODO replace with Guzzle service
        $this->setJobState($job, 'Downloading');
        $downloader = $this->getDownloader();
        
        $this->inputBeingDownloaded = $job->getInput();
        $inputFile = $downloader->downloadFileAdvanced($input->getUri(), array(
            $this,
            'updateDownloadProgress'
        ));
        $this->tempFiles[] = $inputFile;
        $this->inputBeingDownloaded->setCurrentEventProgress(100);
        $this->om->persist($this->inputBeingDownloaded);
        $this->om->flush();
        if ($this->logger) {
            $this->logger->debug('Successfully downloaded ' . $inputFile . ' to ' . $inputFile . ' (' . filesize($inputFile) . ' bytes)');
        }
        $this->inputBeingDownloaded = null;
        
        // Analyze file
        $this->setJobState($job, 'analyzing input');
        $input->setCurrentEvent('Analyzing');
        $input->setCurrentEventProgress(0);
        $this->om->persist($input);
        $this->om->flush();
        $mediaProcessor = $this->getMediaFileProcessor();
        $inputMediaFile = $mediaProcessor->process($inputFile);
        $inputMediaFile->setUrl($input->getUri());
        $inputMediaFile->setTest($job->getTest());
        $input->setMediaFile($inputMediaFile);
        $input->setCurrentEventProgress(100);
        $this->om->persist($input);
        $this->om->flush();
        
        // Queue valid outputs for encoding
        $this->setJobState($job, 'Queuing output');
        $outputFilter = $this->getOutputFilter();
        $outputs = $outputFilter->findValidOutputs($inputMediaFile, $job);
        if (empty($outputs)) {
            if ($this->logger) {
                $this->logger->error('No valid outputs found in job ' . $job->getId());
            }
        }
        
        $translator = new OutputTranslator();
        $job->setOutputs(new ArrayCollection());
        foreach ($outputs as $output) {
            $job->addOutput($output);
            $outputMediaFile = $translator->outputToMediaFile($output, $job->getInput()
                ->getMediaFile());
            $outputMediaFile->setJob($job);
            
            // Publish job to RabbitMQ
            $msg = array(
                'job_id' => $job->getId(),
                'output_id' => $output->getId()
            );
            $jsonmsg = json_encode($msg);
            $this->outputQueueProducer->publish($jsonmsg);
            if ($this->logger) {
                $this->logger->debug("Added output $jsonmsg to queue (" . $output->getFormat() . ')');
            }
        }
        
        // Queue thumbnails generation
        
        // Cleanup
        $downloader->rmTmpFile($job->getInput()
            ->getUri());
        
        $this->setJobState($job, 'Input analyzed');
        if ($this->logger) {
            $this->logger->debug('Analysis of ' . $inputFile . ' complete.');
        }
        return $job;
    }

    public function updateDownloadProgress($ch, $downloadSize, $downloaded, $uploadSize, $uploaded)
    {
        $timestamp = microtime(true) * 1000; // ms
        if ($timestamp - $this->inputBeingDownloadedLastUpdate < 500) {
            return;
        }
        
        $input = $this->inputBeingDownloaded;
        $input->setCurrentEvent('Downloading');
        if ($downloadSize > 0) {
            $input->setCurrentEventProgress(100 * $downloaded / $downloadSize);
        } else {
            $input->setCurrentEventProgress(0);
        }
        $this->om->persist($input);
        $this->om->flush();
        
        $this->inputBeingDownloadedLastUpdate = $timestamp;
    }

    private function setJobState($job, $state)
    {
        $job->setState($state);
        $this->om->persist($job);
        $this->om->flush();
    }

    public function setMediaFileProcessor(MediaFileProcessor $MediaFileProcessor)
    {
        $this->mediaFileProcessor = $mediaFileProcessor;
        return $this;
    }

    public function getMediaFileProcessor()
    {
        if (null === $this->mediaFileProcessor) {
            $this->mediaFileProcessor = new MediaFileProcessor();
        }
        return $this->mediaFileProcessor;
    }

    public function setOutputFilter(OutputFilter $outputFilter)
    {
        $this->outputFilter = $outputFilter;
        return $this;
    }

    public function getOutputFilter()
    {
        if (null === $this->outputFilter) {
            $this->outputFilter = new OutputFilter();
        }
        return $this->outputFilter;
    }

    /**
     *
     * @return Downloader
     */
    public function getDownloader()
    {
        if (null === $this->downloader) {
            $this->downloader = new Downloader();
        }
        return $this->downloader;
    }

    public function setDownloader(Downloader $downloader)
    {
        $this->downloader = $downloader;
        return $this;
    }
}