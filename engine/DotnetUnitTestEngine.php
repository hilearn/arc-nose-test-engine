<?php

/**
 * Dotnet Unit Test Engine for arcanist testing.
 */
class DotnetUnitTestEngine extends ArcanistUnitTestEngine {

    protected $testEngine;
    protected $projectRoot;
    protected $paths;

    /**
     * This test engine supports running all tests.
     */
    protected function supportsRunAllTests() {
        return true;
    }

    /**
     * Determines what executables and test paths to use.
     *
     * @return void
     */
    protected function loadEnvironment() {
        $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();

        $this->paths = $this->getConfigurationManager()->getConfigFromAnySource(
            'unit.engine.paths');

        $this->testEngine = 'dotnet';
    }

    /**
     * Main entry point for the test engine.
     *
     * @return array   Array of test results.
     * @throws Exception
     */
    public function run() {
        $this->loadEnvironment();

        if (empty($this->paths)) {
            return array();
        }

        $results = array();

        // Build the futures for running the tests.
        $futures = array();
        $outputs = array();
        $coverages = array();
        foreach ($this->paths as $test_proj_path) {
            list($future_r, $test_temp, $coverage) =
                $this->buildTestFuture($test_proj_path);
            $futures[$test_proj_path] = $future_r;
            $outputs[$test_proj_path] = $test_temp;
            $coverages[$test_proj_path] = $coverage;
        }

        // Run all of the tests.
        $futures = id(new FutureIterator($futures))
            ->limit(8);

        foreach ($futures as $test_proj_path => $future) {
            list($err, $stdout, $stderr) = $future->resolve();

            $result = $this->parseTestResult(
                $outputs[$test_proj_path],
                $coverages[$test_proj_path]);
            $results[] = $result;
            unlink($outputs[$test_proj_path]);
        }

        return array_mergev($results);
    }


    /**
     * Build the future for running a unit test. This can be overridden to enable
     * support for code coverage via another tool.
     *
     * @param  string  Name of the test assembly.
     * @return array   The future, output filename and coverage filename
     *                 stored in an array.
     */
    protected function buildTestFuture($test_proj_path) {
        $test_temp = 'test_results.trx';

        $folder = Filesystem::resolvePath($this->projectRoot).'/'.$test_proj_path;
        if (phutil_is_windows()) {
            $folder = Filesystem::resolvePath($this->projectRoot).'\\'.$test_proj_path;
        }

        $combined = $folder.'/TestResults/'.$test_temp;
        if (phutil_is_windows()) {
            $combined = $folder.'\\TestResults\\'.$test_temp;
        }

        if (file_exists($combined)) {
            unlink($combined);
        }

        $future = new ExecFuture(
            "%C {$folder} --logger 'trx;LogFileName={$test_temp}'",
            trim($this->testEngine.' test'));
        $future->setCWD($folder);

        return array($future, $combined, null);
    }

    /**
     * Returns null for this implementation as xUnit does not support code
     * coverage directly. Override this method in another class to provide code
     * coverage information (also see @{class:CSharpToolsUnitEngine}).
     *
     * @param  string  The name of the coverage file if one was provided by
     *                 `buildTestFuture`.
     * @return array   Code coverage results, or null.
     */
    protected function parseCoverageResult($coverage) {
        return null;
    }

    /**
     * Parses dotnet test result duration string to seconds in float format
     *
     * @param string Duration in 'hour.minutes.seconds.microseconds' format.
     * @return float
     */
    protected function parseDuration($duration_string)
    {
        $parsed = date_parse_from_format("g.i.s.v", $duration_string);
        return $parsed['hour'] * 3600 + $parsed['minute'] * 60 + $parsed['second'] + $parsed['fraction'];
    }

    /**
     * Parses the test results from dotnet test.
     *
     * @param  string  The name of the xUnit results file.
     * @param  string  The name of the coverage file if one was provided by
     *                 `buildTestFuture`. This is passed through to
     *                 `parseCoverageResult`.
     * @return array   Test results.
     */
    private function parseTestResult($test_tmp, $coverage) {
        $test_dom = new DOMDocument();
        $test_dom->loadXML(Filesystem::readFile($test_tmp));

        $results = array();
        $tests = $test_dom->getElementsByTagName('UnitTestResult');

        foreach ($tests as $test) {
            $name = $test->getAttribute('testName');
            $time = $test->getAttribute('duration');
            $status = ArcanistUnitTestResult::RESULT_UNSOUND;
            switch ($test->getAttribute('outcome')) {
                case 'Passed':
                    $status = ArcanistUnitTestResult::RESULT_PASS;
                    break;
                case 'Failed':
                    $status = ArcanistUnitTestResult::RESULT_FAIL;
                    break;
                case 'Skipped':
                    $status = ArcanistUnitTestResult::RESULT_SKIP;
                    break;
            }
            $userdata = '';
            $reason = $test->getElementsByTagName('Output');
            $failure = $test->getElementsByTagName('ErrorInfo');
            if ($reason->length > 0 || $failure->length > 0) {
                $node = ($reason->length > 0) ? $reason : $failure;
                $message = $node->item(0)->getElementsByTagName('Message');
                if ($message->length > 0) {
                    $userdata = $message->item(0)->nodeValue;
                }
                $stacktrace = $node->item(0)->getElementsByTagName('StackTrace');
                if ($stacktrace->length > 0) {
                    $userdata .= "\n".$stacktrace->item(0)->nodeValue;
                }
            }

            $result = new ArcanistUnitTestResult();
            $result->setName($name);
            $result->setResult($status);
            $result->setDuration($this->parseDuration($time));
            $result->setUserData($userdata);
            if ($coverage != null) {
                $result->setCoverage($this->parseCoverageResult($coverage));
            }
            $results[] = $result;
        }

        return $results;
    }

}