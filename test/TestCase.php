<?php
/**
 * @file
 * Base test case.
 */


namespace HPCloud;

require_once  'mageekguy.atoum.phar';
require_once 'src/HPCloud/Bootstrap.php';

use \mageekguy\atoum;

class TestCase extends atoum\test {

  public function __construct(score $score = NULL, locale $locale = NULL, adapter $adapter = NULL) {

    $this->setTestNamespace('Tests\Units');
    if (file_exists('test/settings.ini')) {
      $this->settings = parse_ini_file('test/settings.ini');
    }
    else {
      throw new Exception('Could not access test/settings.ini');
    }
    \HPCloud\Bootstrap::useAutoloader();

    parent::__construct($score, $locale, $adapter);
  }
}
