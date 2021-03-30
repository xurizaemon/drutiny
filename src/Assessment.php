<?php

namespace Drutiny;

use Async\ForkManager;
use Drutiny\AuditResponse\AuditResponse;
use Drutiny\AuditResponse\NoAuditResponseFoundException;
use Drutiny\Entity\ExportableInterface;
use Drutiny\Entity\SerializableExportableTrait;
use Drutiny\Sandbox\ReportingPeriodTrait;
use Drutiny\Target\TargetInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class Assessment implements ExportableInterface, AssessmentInterface
{
    use ReportingPeriodTrait;
    use SerializableExportableTrait {
      import as importUnserialized;
    }

    /**
     * @var string URI
     */
    protected string $uri = '';
    protected array $results = [];
    protected bool $successful = true;
    protected int $severityCode = 1;
    protected LoggerInterface $logger;
    protected ContainerInterface $container;
    protected ForkManager $forkManager;
    protected array $statsByResult = [];
    protected array $statsBySeverity = [];
    protected array $policyOrder = [];
    protected ProgressBar $progressBar;
    protected string $uuid;

    public function __construct(LoggerInterface $logger, ContainerInterface $container, ForkManager $forkManager, ProgressBar $progressBar)
    {
        $this->logger = $logger;
        $this->container = $container;
        $this->forkManager = $forkManager;
        $this->progressBar = $progressBar;

        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $this->uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function getUuid():string
    {
      return $this->uuid;
    }

    public function setUri($uri = 'default')
    {
        $this->uri = $uri;
        return $this;
    }

    /**
     * Assess a Target.
     *
     * @param TargetInterface $target
     * @param array $policies each item should be a Drutiny\Policy object.
     * @param DateTime $start The start date of the reporting period. Defaults to -1 day.
     * @param DateTime $end The end date of the reporting period. Defaults to now.
     * @param bool $remediate If an Drutiny\Audit supports remediation and the policy failes, remediate the policy. Defaults to FALSE.
     */
    public function assessTarget(TargetInterface $target, array $policies, \DateTime $start = null, \DateTime $end = null, $remediate = false)
    {
        $start = $start ?: new \DateTime('-1 day');
        $end   = $end ?: new \DateTime();

        // Record the reporting period in the assessment so we can pull it
        // later when rendering the report.
        $this->setReportingPeriod($start, $end);

        $policies = array_filter($policies, function ($policy) {
            return $policy instanceof Policy;
        });

        $this->progressBar->setMaxSteps($this->progressBar->getMaxSteps() + count($policies));

        $promises = [];
        foreach ($policies as $policy) {
            $this->policyOrder[] = $policy->name;
            $this->logger->info("Assessing '{policy}' against {uri}", [
              'policy' => $policy->name,
              'uri' => $this->uri,
            ]);

            $audit = $this->container->get($policy->class);
            $audit->setParameter('reporting_period_start', $start)
                  ->setParameter('reporting_period_end', $end);

            if ($target !== $audit->getTarget()) {
              throw new \Exception("Audit target not the same as assessment target.");
            }
            $audit->getTarget()->setUri($this->uri);

            $this->forkManager->run(function () use ($audit, $policy, $remediate) {
              return $audit->execute($policy, $remediate);
            });
        }
        $returned = 0;
        foreach ($this->forkManager->receive() as $response) {
            $returned++;
            $this->progressBar->advance();
            $this->progressBar->setMessage('Audit response of ' . $response->getPolicy()->name . ' recieved.');

            // Attempt remediation.
            if ($remediate && !$response->isSuccessful()) {
                $this->logger->info("\xE2\x9A\xA0 Remediating " . $response->getPolicy()->title);
                $this->setPolicyResult($sandbox->remediate());
            }
            // Omit irrelevant AuditResponses.
            elseif (!$response->isIrrelevant()) {
                $this->setPolicyResult($response);
            }
            else {
              $this->logger->info("Omitting policy result from assessment: ".$response->getPolicy()->name);
            }

            $this->logger->info(sprintf('Policy "%s" assessment on %s completed: %s.', $response->getPolicy()->title, $this->uri(), $response->getType()));
        }

        $total = count($policies);
        $this->logger->debug("Assessment returned $returned/$total from the fork manager.");

        return $this;
    }

    /**
     * Set the result of a Policy.
     *
     * The result of a Policy is unique to an assessment result set.
     *
     * @param AuditResponse $response
     */
    public function setPolicyResult(AuditResponse $response)
    {
        $this->results[$response->getPolicy()->name] = $response;

        // Set the overall success state of the Assessment. Considered
        // a success if all policies pass.
        $this->successful = $this->successful && $response->isSuccessful();

        // If the policy failed its assessment and the severity of the Policy
        // is higher than the current severity of the assessment, then increase
        // the severity of the overall assessment.
        $severity = $response->getPolicy()->getSeverity();
        if (!$response->isSuccessful() && ($this->severityCode < $severity)) {
            $this->severityCode = $severity;
        }

        // Statistics.
        $this->statsByResult[$response->getType()] = $this->statsByResult[$response->getType()] ?? 0;
        $this->statsByResult[$response->getType()]++;

        $this->statsBySeverity[$response->getSeverity()][$response->getType()] = $this->statsBySeverity[$response->getSeverity()][$response->getType()] ?? 0;
        $this->statsBySeverity[$response->getSeverity()][$response->getType()]++;
    }

    public function getSeverityCode():int
    {
        return $this->severityCode;
    }

    /**
     * Get the overall outcome of the assessment.
     */
    public function isSuccessful()
    {
        return $this->successful;
    }

    /**
     * Get an AuditResponse object by Policy name.
     *
     * @param string $name
     * @return AuditResponse
     */
    public function getPolicyResult(string $name)
    {
        if (!isset($this->results[$name])) {
            throw new NoAuditResponseFoundException($name, "Policy '$name' does not have an AuditResponse. Found " . implode(', ', array_keys($this->results)));
        }
        return $this->results[$name];
    }

    /**
     * Get the results array of AuditResponse objects.
     *
     * @return array of AuditResponse objects.
     */
    public function getResults()
    {
        return array_filter(array_map(function ($name) {
            return $this->results[$name] ?? false;
        }, $this->policyOrder));
    }

    /**
     * Get the uri of Assessment object.
     *
     * @return string uri.
     */
    public function uri()
    {
        return $this->uri;
    }

    public function getStatsByResult()
    {
      return $this->statsByResult;
    }

    public function getStatsBySeverity()
    {
      return $this->statsBySeverity;
    }

    /**
     * {@inheritdoc}
     */
    public function export()
    {
      return [
        // 'statsBySeverity' => $this->statsBySeverity,
        // 'statsBySeverity' => $this->statsBySeverity,
        'uri' => $this->uri,
        'uuid' => $this->uuid,
        'results' => $this->results,
        'policyOrder' => $this->policyOrder,
      ];
    }

    public function import(array $export)
    {
      foreach ($export['results'] as $result) {
          $this->setPolicyResult($result);
      }
      unset($export['results']);
      $this->importUnserialized($export);
      $this->container = drutiny();
      $this->logger = drutiny()->get('logger');
      $this->async = drutiny()->get('async');
    }
}
