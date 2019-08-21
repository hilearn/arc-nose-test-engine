<?php

/**
 * Basic 'nose' unit test engine wrapper.
 *
 * Works with 1.3.7
 */
final class PythonMultiTestEngine extends ArcanistUnitTestEngine
{

    private $parser;

    protected function supportsRunAllTests()
    {
        return true;
    }

    public function run()
    {
        $cm = $this->getConfigurationManager();
        $roots = $cm->getConfigFromAnySource("unit.engine.roots");

        # Erase outdated coverage so there are no exceptions when reporting it.
        (new ExecFuture('coverage erase'))->resolvex();

        if ($this->getRunAllTests()) {
            $all_tests = array();
            foreach ($roots as $root => $testers) {

                $all_tests[$root] = glob(Filesystem::resolvePath("$root/tests/**/test_*.py"));

            }
            return $this->runRootTests($all_tests, $roots);
        }

        $paths = $this->getPaths();

        $affected_tests = array();

        foreach ($paths as $path) {
            $absolute_path = Filesystem::resolvePath($path);

            if (is_dir($absolute_path)) {
                # TODO: Not sure what this does.
                $absolute_test_path = Filesystem::resolvePath('tests/' . $path);
                if (is_readable($absolute_test_path)) {
                    $affected_tests[] = $absolute_test_path;
                }
            }

            if (is_readable($absolute_path)) {
                $path_resolved = false;

                $filename = basename($path);
                $directory = dirname($path);

                foreach ($roots as $root => $testers) {
                    $p = strpos($path, $root);
                    if ( strpos($path, $root) === 0) {
                        $rel_dir = substr($directory, strlen($root));
                        $test_path = $root . '/tests' . $rel_dir . '/test_' . $filename;
                        $absolute_test_path = Filesystem::resolvePath($test_path);

                        if (is_readable($absolute_test_path)) {
                            $affected_tests[$root][] = $absolute_test_path;
                        }
                        break;
                    }
                    
                }
            }
        }

        return $this->runRootTests($affected_tests, $roots);

    }

    private function runRootTests($test_paths, $root_config) {
        $all_results = array();
        foreach ($test_paths as $root => $tests) {
            $results = $this->runTests($tests, $root);
            $all_results = array_merge($all_results, $results);
        }
        return $all_results;
    }

    public function runTests($test_paths, $source_path)
    {
        if (empty($test_paths)) {
            return array();
        }

        putenv("PYTHONPATH=$source_path");

        $futures = array();
        $tmpfiles = array();

        foreach ($test_paths as $test_path) {
            $xunit_tmp = new TempFile();
            $cover_tmp = new TempFile();

            $future = $this->buildNoseTestFuture($test_path, $xunit_tmp, $cover_tmp, $source_path);

            $futures[$test_path] = $future;
            $tmpfiles[$test_path] = array(
                'xunit' => $xunit_tmp,
                'cover' => $cover_tmp,
            );
        }
        $results = array();
        $futures = id(new FutureIterator($futures))->limit(4);

        foreach ($futures as $test_path => $future) {
            try {
                list($stdout, $stderr) = $future->resolvex();
            } catch (CommandException $exc) {
                if ($exc->getError() > 1) {
                    // 'nose' returns 1 when tests are failing/broken.
                    throw $exc;
                }
            }

            $xunit_tmp = $tmpfiles[$test_path]['xunit'];
            $cover_tmp = $tmpfiles[$test_path]['cover'];

            $this->parser = new ArcanistXUnitTestResultParser();
            $results[] = $this->parseTestResults(
                $source_path,
                $xunit_tmp,
                $cover_tmp);
        }

        return array_mergev($results);
    }

    public function buildNoseTestFuture($path, $xunit_tmp, $cover_tmp, $cover_package)
    {

        $cmd_line = csprintf(
            'nosetests --with-xunit --xunit-file=%s',
            $xunit_tmp);

        if ($this->getEnableCoverage() !== false) {
            $cmd_line .= csprintf(
                ' --with-coverage --cover-xml --cover-xml-file=%s --cover-package=%s',
                $cover_tmp, $cover_package);
        }

        return new ExecFuture('%C %s', $cmd_line, $path);
    }

    public function parseTestResults($source_path, $xunit_tmp, $cover_tmp)
    {
        $results = $this->parser->parseTestResults(Filesystem::readFile($xunit_tmp));

        // coverage is for all testcases in the executed $path
        if ($this->getEnableCoverage() !== false) {
            $coverage = $this->readCoverage($cover_tmp, $source_path);
            foreach ($results as $result) {
                $result->setCoverage($coverage);
            }
        }

        return $results;
    }

    public function readCoverage($cover_file, $source_path)
    {
        $coverage_xml = Filesystem::readFile($cover_file);
        if (strlen($coverage_xml) < 1) {
            return array();
        }
        $coverage_dom = new DOMDocument();
        $coverage_dom->loadXML($coverage_xml);

        $reports = array();
        $classes = $coverage_dom->getElementsByTagName('class');
        $root = $this->getWorkingCopy()->getProjectRoot();

        foreach ($classes as $class) {
            $path = $class->getAttribute('filename');

            if (!Filesystem::isDescendant($path, $root)) {
                continue;
            }

            // get total line count in file
            $line_count = count(phutil_split_lines(Filesystem::readFile($path)));

            $coverage = '';
            $start_line = 1;
            $lines = $class->getElementsByTagName('line');
            for ($ii = 0; $ii < $lines->length; $ii++) {
                $line = $lines->item($ii);

                $next_line = (int)$line->getAttribute('number');
                for ($start_line; $start_line < $next_line; $start_line++) {
                    $coverage .= 'N';
                }

                if ((int)$line->getAttribute('hits') == 0) {
                    $coverage .= 'U';
                } else if ((int)$line->getAttribute('hits') > 0) {
                    $coverage .= 'C';
                }

                $start_line++;
            }

            if ($start_line < $line_count) {
                foreach (range($start_line, $line_count) as $line_num) {
                    $coverage .= 'N';
                }
            }

            $reports[$path] = $coverage;
        }

        return $reports;
    }

}
