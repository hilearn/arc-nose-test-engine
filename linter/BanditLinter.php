<?php
/**
 * Lints Python source files using bandit.
 * Bandit tested version - 1.6.2.
 */
final class BanditLinter extends ArcanistExternalLinter {

  private $severityLevel = null;

  public function getInfoName() {
    return 'bandit';
  }

  public function getInfoURI() {
    return '';
  }

  public function getInfoDescription() {
    return pht('Use bandit for processing specified files.');
  }

  public function getLinterName() {
    return 'bandit';
  }

  public function getLinterConfigurationName() {
    return 'bandit';
  }

  public function getDefaultBinary() {
    return 'bandit';
  }

  public function getInstallInstructions() {
    return pht('Install bandit with `pip install bandit`');
  }

  protected function getDefaultMessageSeverity($code) {
    return ArcanistLintSeverity::SEVERITY_WARNING;
  }

  public function getLintSeverityMap() {
    return [
        'LOW' => ArcanistLintSeverity::SEVERITY_ADVICE,
        'MEDIUM' => ArcanistLintSeverity::SEVERITY_ERROR,
        'HIGH' => ArcanistLintSeverity::SEVERITY_ERROR
    ];
  }

  public function getVersion() {
    list($stdout) = execx('%C --version', $this->getExecutableCommand());

    $matches = array();
    $regex = '/(?P<bandit>\d+\.\d+\.\d+)/';
    if (preg_match($regex, $stdout, $matches)) {
      return $matches['bandit'][0];
    } else {
      return false;
    }
  }

  protected function getMandatoryFlags() {
    return array(
      '--recursive',
      '--format',
      'json'
    );
  }

  protected function parseLinterOutput($path, $err, $stdout, $stderr) {
    $output = json_decode($stdout, TRUE);
    $messages = array();
    foreach ($output['results'] as $message) {
      $messages []= id(new ArcanistLintMessage())
        ->setPath($message['filename'])
        ->setLine($message['line_number'])
        ->setCode($message['test_id'])
        ->setSeverity($this->getLintMessageSeverity($message['issue_severity']))
        ->setName($message['test_name'])
        ->setDescription("{$message['issue_text']}\n{$message['more_info']}");
    }
    return $messages;
  }
}

