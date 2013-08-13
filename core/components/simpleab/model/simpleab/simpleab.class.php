<?php
/**
 * SimpleAB
 *
 * Copyright 2011-2013 by Mark Hamstra <support@modmore.com>
 *
 * This file is part of SimpleAB.
 *
 * For license terms, please review core/components/simpleab/docs/license.txt.
 *
 * @package simpleab
*/

class SimpleAB {
    /** @var \modX $modx */
    public $modx;
    /** @var array Array of configuration options, primarily paths. */
    public $config = array();

    public $considerPreviousPicks = true;

    public $lastPickDetails = array();

    public $cacheOptions = array(
        xPDO::OPT_CACHE_KEY => 'simpleab',
    );

    protected $_defaultSession = array(
        '_picked' => array(),
    );


    /**
     * @param \modX $modx
     * @param array $config
     */
    function __construct(modX &$modx,array $config = array()) {
        $this->modx =& $modx;

        $basePath = $this->modx->getOption('simpleab.core_path',$config,$this->modx->getOption('core_path').'components/simpleab/');
        $assetsUrl = $this->modx->getOption('simpleab.assets_url',$config,$this->modx->getOption('assets_url').'components/simpleab/');
        $assetsPath = $this->modx->getOption('simpleab.assets_path',$config,$this->modx->getOption('assets_path').'components/simpleab/');
        $this->config = array_merge(array(
            'basePath' => $basePath,
            'corePath' => $basePath,
            'modelPath' => $basePath.'model/',
            'processorsPath' => $basePath.'processors/',
            'elementsPath' => $basePath.'elements/',
            'templatesPath' => $basePath.'templates/',
            'assetsPath' => $assetsPath,
            'assetsUrl' => $assetsUrl,
            'jsUrl' => $assetsUrl.'js/',
            'cssUrl' => $assetsUrl.'css/',
            'connectorUrl' => $assetsUrl.'connector.php',
            'hideLogo' => (bool)$this->modx->getOption('simpleab.hide_logo', null, false),
        ),$config);

        $this->modx->lexicon->load('simpleab:default');

        $modelPath = $this->config['modelPath'];
        $this->modx->addPackage('simpleab',$modelPath);
        $this->modx->loadClass('sabConversion', $modelPath.'simpleab/');
        $this->modx->loadClass('sabTest', $modelPath.'simpleab/');
        $this->modx->loadClass('sabVariation', $modelPath.'simpleab/');
        $this->modx->loadClass('sabPick', $modelPath.'simpleab/');

        $this->considerPreviousPicks = (bool)$this->modx->getOption('simpleab.use_previous_picks', null, true);
    }

    /**
     * @return array
     */
    public function getUserData() {
        if (isset($_SESSION['_simpleab'])) {
            $data = $_SESSION['_simpleab'];
        }
        else {
            $data = $_SESSION['_simpleab'] = $this->_defaultSession;
        }
        return $data;
    }

    /**
     * Picks one of the supplied array of options to display.
     *
     * Makes sure that if the user has previously been shown an option for this key before, it will show the same.
     * Otherwise it will either pick randomly or with a bit of logic.
     *
     * @param sabTest $test
     * @param array $variations
     * @param array $userData
     *
     * @return mixed
     */
    public function pickOne (sabTest $test, array $variations = array(), array $userData = array()) {
        $testId = $test->get('id');
        $options = array_keys($variations);

        $theOne = false;
        $mode = '';

        $totalConversions = 0;
        foreach ($variations as $variation) {
            $totalConversions = $variation['conversions'] + $totalConversions;
        }
        /**
         * Check if we have picked something for this element already for this user. If we did, we'll want to
         * show them the same one.
         */
        if ($this->considerPreviousPicks && array_key_exists('_picked', $userData) && array_key_exists($testId, $userData['_picked'])) {
            $previous = $userData['_picked'][$testId];
            // Make sure the previously chosen one is still an option..
            if (in_array($previous, $options)) {
                $mode = 'previous';
                $theOne = $previous;
            }
        }

        /**
         * If we didn't get a previous pick, we'll have to do some logic to get one.
         */
        if (!$theOne) {
            // Check if we can pick it randomly, by matching the total historic conversions
            // to the threshold.
            $random = $this->pickOneRandomly($test, $totalConversions);

            // Yay, we can do it randomly!
            if ($random) {
                $mode = 'random';
                shuffle($options);
                $theOne = reset($options);
                $this->registerPick($testId, $theOne);
            }

            // No randomness involved - use smart optimize mode to pick the best performing variation.
            else {
                $mode = 'bestpick';
                $highestRate = 0;
                $highestVariation = 0;
                foreach ($variations as $variationId => $variation) {
                    if ($variation['conversionrate'] > $highestRate) {
                        $highestRate = $variation['conversionrate'];
                        $highestVariation = $variationId;
                    }
                }
                $theOne = $highestVariation;
                $this->registerPick($testId, $theOne);
            }
        }

        $this->lastPickDetails = array(
            'test' => $testId,
            'mode' => $mode,
            'pick' => $theOne,
            'variation' => $variations[$theOne],
            'variations' => $variations,
        );
        if (isset($variations[$theOne])) {
            return $variations[$theOne];
        }
        return null;
    }

    /**
     * Decides if we need to use a random test or not.
     *
     * @param sabTest $test
     * @param $conversions
     *
     * @return bool
     */
    public function pickOneRandomly(sabTest $test, $conversions) {
        if (!$test->get('smartoptimize')) {
            return true;
        }
        $random = ($conversions <= $test->get('threshold'));
        if (!$random) {
            $randomChance = rand(0,100);
            if ($randomChance < $test->get('randomize')) {
                $random = true;
            }
        }
        return $random;
    }

    /**
     * @param $key
     * @param $theOne
     */
    public function registerPick($key, $theOne) {
        /** Set the session */
        if (!isset($_SESSION['_simpleab'])) {
            $_SESSION['_simpleab'] = $this->_defaultSession;
        }
        $data =& $_SESSION['_simpleab'];
        $data['_picked'][$key] = $theOne;

        /** Log the pick in the database for stats. */
        $pick = $this->modx->newObject('sabPick');
        $pick->fromArray(array(
            'test' => $key,
            'variation' => $theOne,
            'date' => date('Ymd'),
        ));
        $pick->save();
    }

    /**
     * @param $tests
     * @param bool $resetPickForTest
     */
    public function registerConversion($tests, $resetPickForTest = true) {
        $userData = $this->getUserData();
        $visitedTests = $userData['_picked'];

        /** Allow tests to be passed as "*" to indicate all visited tests. */
        if (!is_array($tests) && ($tests == '*')) {
            $tests = array_keys($visitedTests);
        }

        /** Make test ID string into array. */
        if (!is_array($tests)) {
            $tests = explode(',', $tests);
        }

        foreach ($tests as $testId) {
            /**
             * Verify if test exists.
             */
            $test = $this->modx->call('sabTest','getTest', array(&$this->modx, $testId));
            if (!($test instanceof sabTest)) continue;

            /**
             * We'll need the shown variation from the users' data.
             */
            $variationId = 0;
            if (array_key_exists($testId, $visitedTests)) {
                $variationId = (int)$visitedTests[$testId];
            }
            if (!$variationId || ($variationId < 1)) continue;

            /**
             * Verify if variation exists.
             */
            $variationExists = (bool)$this->modx->getCount('sabVariation', array('id' => $variationId, 'test' => $testId));
            if (!$variationExists) continue;

            /** @var sabConversion $conversion */
            $conversion = $this->modx->newObject('sabConversion');
            $conversion->fromArray(array(
                'test' => $testId,
                'variation' => $variationId,
                'date' => date('Ymd'),
                'value' => 1,
            ));
            if ($conversion->save()) {
                if ($resetPickForTest) {
                    unset($_SESSION['_simpleab']['_picked'][$testId]);
                }
            }
            else {
                $this->modx->log(modX::LOG_LEVEL_ERROR,'[SimpleAB.sabConversionHook] Error occurred trying to save new sabConversion object.');
            }
        }
    }

    /**
     * @param modResource $resource
     *
     * @return array
     */
    public function getTestsForResource(modResource $resource) {
        $registry = $this->modx->cacheManager->get('registry', $this->cacheOptions);
        if (!$registry || empty($registry)) {
            $registry = $this->createTestRegistry();
        }

        $template = $resource->get('template');
        $resource = $resource->get('id');

        $tests = array();

        if (array_key_exists($template, $registry['templates'])) {
            $tests = array_merge($tests, $registry['templates'][$template]);
        }
        if (array_key_exists($resource, $registry['resources'])) {
            $tests = array_merge($tests, $registry['resources'][$resource]);
        }

        $tests = array_unique($tests);
        return $tests;
    }

    /**
     * @return array
     */
    public function createTestRegistry() {
        $registry = array(
            'resources' => array(),
            'templates' => array(),
        );
        $tests = $this->modx->getCollection('sabTest', array('archived' => false));

        /** @var sabTest $test */
        foreach ($tests as $test) {
            $testId = $test->get('id');
            $resources = (string)$test->get('resources');
            $templates = (string)$test->get('templates');

            if (!empty($resources)) {
                $resources = explode(',', $resources);
                foreach ($resources as $resourceId) {
                    if (!isset($registry['resources'][$resourceId])) {
                        $registry['resources'][$resourceId] = array();
                    }
                    $registry['resources'][$resourceId][] = $testId;
                }
            }
            if (!empty($templates)) {
                $templates = explode(',', $templates);
                foreach ($templates as $templateId) {
                    if (!isset($registry['templates'][$templateId])) {
                        $registry['templates'][$templateId] = array();
                    }
                    $registry['templates'][$templateId][] = $testId;
                }
            }
        }
        $this->modx->cacheManager->set('registry', $registry, 0, $this->cacheOptions);
        return $registry;
    }

    /**
     * Creates a simple admin preview box and puts it in the markup.
     *
     * @param sabTest $test
     * @param array $variations
     */
    public function registerAdminPreviewBox(sabTest $test, array $variations = array()) {
        /** @var string $variationsDropdown Build a <select> field with variations  */
        $variationsDropdown = '<select name="sabVariation" onchange="this.form.submit();">';
        foreach ($variations as $key => $options) {
            $selected = ($_GET['sabVariation'] == $key) ? ' selected="selected" ' : '';
            $variationsDropdown .= '<option value="'.$key.'"'.$selected.'>'.$options['name'].'</option>';
        }
        $variationsDropdown .= '</select>';

        /** @var string $thisPageUrl The URL to the current page. */
        $thisPageUrl = $this->modx->makeUrl($this->modx->resource->get('id'), '', '', 'full');

        $html = <<<HTML
<div style="position: fixed; bottom: 0px; left: 0px; background: #1f4a7f; color: white; margin: 0; padding: 15px; -webkit-border-radius: 0px 10px 0px 0px; border-radius: 0px 10px 0px 0px; border-top: 1px solid #fff; border-right: 1px solid #fff; z-index: 10000;">
<p style="margin: 0 0 10px 0; padding: 0; width: 100%;font-size: 125%;">SimpleAB Admin Preview</p>
<form action="{$thisPageUrl}" method="get">
    <input type="hidden" name="sabTest" value="{$test->get('id')}" />
    <p>
        <strong>Test:</strong> {$test->name} <br />
        <strong>Variation</strong> {$variationsDropdown}
    </p>
</form>
</div>
HTML;

        $this->modx->regClientHTMLBlock($html);
    }
}

