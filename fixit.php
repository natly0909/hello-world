<?php
class Fixit
{
    const BX_ROOT = '/bitrix';

    const MAX_STEP = 100;
    const MAX_STEP_TIME = 1;

    const DIR_PERMISSIONS = 0755;
    const FILE_PERMISSIONS = 0644;

    const UPLOAD_ZIP_DIR = 'aspro_fixit';

    protected static $instance;

    public static function getInstance()
    {
        if (!static::$instance) {
            static::startSession();
            static::$instance = new static();
        }

        return static::$instance;
    }

    protected static function startSession()
    {
        if (
            array_key_exists('session_id', $_REQUEST)
            && $_REQUEST['session_id']
        ) {
            session_id($_REQUEST['session_id']);
        } else {
            $bCLear = true;
        }

        session_start();

        if ($bCLear) {
            unset($_SESSION['Fixit']);
        }
    }

    protected $sd;
    protected $zip;

    protected function __construct()
    {
        ob_start();

        if (
            !isset($_SESSION['Fixit'])
            || !is_array($_SESSION['Fixit'])
            || !isset($_SESSION['Fixit']['error'])
            || !is_string($_SESSION['Fixit']['error'])
            || !isset($_SESSION['Fixit']['options'])
            || !is_array($_SESSION['Fixit']['options'])
            || !isset($_SESSION['Fixit']['cnt_checked_files'])
            || !is_int($_SESSION['Fixit']['cnt_checked_files'])
            || !isset($_SESSION['Fixit']['finded_files'])
            || !is_array($_SESSION['Fixit']['finded_files'])
            || !isset($_SESSION['Fixit']['fixed_files'])
            || !is_array($_SESSION['Fixit']['fixed_files'])
            || !isset($_SESSION['Fixit']['stages'])
            || !is_array($_SESSION['Fixit']['stages'])
        ) {
            $_SESSION['Fixit'] = [
                'error' => '',
                'options' => [],
                'cnt_checked_files' => 0,
                'finded_files' => [],
                'fixed_files' => [],
                'stages' => [
                    'finish' => [],
                ],
            ];
        }

        $this->sd = &$_SESSION['Fixit'];
    }

    public function __wakeup()
    {
    }

    protected function __clone()
    {
    }

    public function getStep()
    {
        $step = intval(isset($_REQUEST['step']) ? $_REQUEST['step'] : 0);
        $step = $step > 0 ? $step : 0;

        return $step;
    }

    public function nextStep($bForce = false)
    {
        $nextStep = $this->getStep() + 1;
        $action = $this->getAction();

        $url = '?session_id='.session_id().'&action='.htmlspecialchars($action).'&step='.intval($nextStep);

        if ($bForce) {
            ob_clean();
            header('Location: '.$url);
            exit;
        } else {
            ?>
			<script>
			setTimeout(function() {
				location.href = <?php var_export($url); ?>;
			}, 2000);
			</script>
			<?php
        }
    }

    public function getOptions()
    {
        $options = [
            'create_back' => 1,
            'create_zip' => 1,
            'self_deletion' => 1,
            'full_scan' => 0,
            'files' => [],
        ];

        if (
            isset($this->sd['options'])
            && is_array($this->sd['options'])
        ) {
            $options = array_merge($options, $this->sd['options']);
        }

        if (isset($_REQUEST['options']['create_back'])) {
            $options['create_back'] = boolval($_REQUEST['options']['create_back']);
        }

        if (isset($_REQUEST['options']['create_zip'])) {
            $options['create_zip'] = boolval($_REQUEST['options']['create_zip']);
        }

        if (isset($_REQUEST['options']['self_deletion'])) {
            $options['self_deletion'] = boolval($_REQUEST['options']['self_deletion']);
        }

        if (isset($_REQUEST['options']['full_scan'])) {
            $options['full_scan'] = boolval($_REQUEST['options']['full_scan']);
        }

        if (isset($_REQUEST['options']['files']) && is_array($_REQUEST['options']['files'])) {
            $options['files'] = $_REQUEST['options']['files'];
        }

        $options['create_zip'] &= $this->canZip();

        return $options;
    }

    public function canZip()
    {
        return class_exists('ZipArchive');
    }

    public function isWindows()
    {
        static $result;

        if (!isset($result)) {
            $result = 'WIN' === strtoupper(substr(PHP_OS, 0, 3));
        }

        return $result;
    }

    protected function checkBitrix()
    {
        $dir = $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT;

        if (
            !file_exists($dir)
            || !is_dir($dir)
        ) {
            throw new Exception('Папка '.htmlspecialchars(static::BX_ROOT).' не найдена в корне сайта. <br /> Разместите скрипт в корне сайта с установленным 1С-Битрикс');
        }
    }

    public function execute()
    {
        try {
            $method = $this->getActionMethod();
            $this->$method();
        } catch (Exception $e) {
            $this->setError($e->getMessage());
        }

        $this->closeZip();

        ob_clean();
    }

    public function getActionMethod()
    {
        $action = $this->getAction();
        $method = $action.'Action';
        if (!method_exists($this, $method)) {
            $method = 'mainAction';
        }

        return $method;
    }

    public function getAction()
    {
        $action = htmlspecialchars(trim(isset($_REQUEST['action']) ? $_REQUEST['action'] : ''));
        $action = strlen($action) ? $action : 'main';

        return $action;
    }

    public function mainAction()
    {
        $this->checkBitrix();
    }

    public function isMainAction()
    {
        return 'mainAction' === $this->getActionMethod();
    }

    public function previewAction()
    {
        $this->checkBitrix();

        $options = $this->getOptions();
        $options['files'] = [];

        $step = $this->getStep();
        if ($step > static::MAX_STEP) {
            throw new Exception('Превышено максимальное число шагов ('.static::MAX_STEP.').<br />Проверьте отсутствие зацикленности в структуре папок.');
        }

        if (!$step) {
            $remainingDirs = $arExcludeSubDirs = [];
            if ($options['full_scan']) {
                $remainingDirs = [
                    $_SERVER['DOCUMENT_ROOT'],
                ];
                $arExcludeSubDirs = [
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/cache',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/managed_cache',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/stack_cache',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/updates',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/panel',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/components/bitrix',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/wizards/bitrix',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/gadgest/bitrix',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/blocks/bitrix',
                    $_SERVER['DOCUMENT_ROOT'].'/local/components/bitrix',
                    $_SERVER['DOCUMENT_ROOT'].'/local/wizards/bitrix',
                    $_SERVER['DOCUMENT_ROOT'].'/local/gadgest/bitrix',
                    $_SERVER['DOCUMENT_ROOT'].'/local/blocks/bitrix',
                    __DIR__,
                ];
            } else {
                $remainingDirs = [
                    $_SERVER['DOCUMENT_ROOT'],
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/components/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/modules/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/tools/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/gadgets/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/wizards/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/blocks/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/templates',
                    $_SERVER['DOCUMENT_ROOT'].'/local/components/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].'/local/modules/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].'/local/tools/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].'/local/gadgets/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].'/local/wizards/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].'/local/blocks/aspro*',
                    $_SERVER['DOCUMENT_ROOT'].'/local/templates',
                ];
                $arExcludeSubDirs = [
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT,
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/cache',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/managed_cache',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/stack_cache',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/updates',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/panel',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/components',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/modules',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/tools',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/gadgets',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/wizards',
                    $_SERVER['DOCUMENT_ROOT'].static::BX_ROOT.'/blocks',
                    $_SERVER['DOCUMENT_ROOT'].'/local/components',
                    $_SERVER['DOCUMENT_ROOT'].'/local/modules',
                    $_SERVER['DOCUMENT_ROOT'].'/local/tools',
                    $_SERVER['DOCUMENT_ROOT'].'/local/gadgets',
                    $_SERVER['DOCUMENT_ROOT'].'/local/wizards',
                    $_SERVER['DOCUMENT_ROOT'].'/local/blocks',
                    $_SERVER['DOCUMENT_ROOT'].'/upload',
                    $_SERVER['DOCUMENT_ROOT'].'/.git',
                    $_SERVER['DOCUMENT_ROOT'].'/.idea',
                    $_SERVER['DOCUMENT_ROOT'].'/.vscode',
                    __DIR__,
                ];
            }

            $this->sd = [
                'error' => '',
                'options' => $options,
                'cnt_checked_files' => 0,
                'finded_files' => [],
                'fixed_files' => [],
                'zip_path' => '',
                'remaining_dirs' => $remainingDirs,
                'exclude_subdirs' => $arExcludeSubDirs,
                'remaining_files' => [],
                'unlink_files' => [
                    '/ajax/error_log_logic.php',
                    '/ajax/_error_log_logic.php.back',
                    '/ajax/js_error.php',
                    '/ajax/_js_error.php.back',
                    '/ajax/js_error.txt',
                    '/ajax/_js_error.txt.back',
                ],
                'patch_files' => [
                    '/form/index.php' => [
                        [
                            '<?elseif($form_id == \'TABLES_SIZE\'):?>',
                        ],
                        '<?// Don\'t use it! Update the module!?>'."\n".'<?elseif($form_id == \'TABLES_SIZE\' && false):?>',
                    ],
                    '/ajax/form.php' => [
                        [
                            '<?elseif($form_id === \'TABLES_SIZE\'):?>',
                            '<?elseif($form_id == \'TABLES_SIZE\'):?>',
                        ],
                        '<?// Don\'t use it! Update the module!?>'."\n".'<?elseif($form_id == \'TABLES_SIZE\' && false):?>',
                    ],
                ],
                'stages' => [
                    'unserialize' => [],
                    'finish' => [],
                ],
            ];
        } else {
            $stage = $this->getStage();
            $maxtime = time() + static::MAX_STEP_TIME;

            while (
                time() <= $maxtime
                && !$this->isFinishStage()
            ) {
                if ('unserialize' === $stage) {
                    $arExcludeSubDirNames = ['.', '..'];

                    if (empty($this->sd['remaining_dirs'])) {
                        $this->nextStage();
                    } else {
                        $dir = array_shift($this->sd['remaining_dirs']);
                        if ($dir) {
                            $dir = rtrim($dir, '/');

                            if ($this->sd['unlink_files']) {
                                foreach ($this->sd['unlink_files'] as $unlinkFile) {
                                    $unilnkFile = $dir.$unlinkFile;
                                    if (@file_exists($dir.$unlinkFile)) {
                                        $this->sd['finded_files'][$unilnkFile] = 'D';
                                    }
                                }
                            }

                            $phpFiles = array_merge(
                                (array) glob($dir.'/*.php', GLOB_NOSORT),
                                (array) glob($dir.'/.*.php', GLOB_NOSORT),
                                (array) glob($dir.'/,*.php', GLOB_NOSORT)
                            );

                            $subDirs = array_merge(
                                (array) glob($dir.'/*', GLOB_ONLYDIR),
                                (array) glob($dir.'/.*', GLOB_ONLYDIR),
                                (array) glob($dir.'/,*', GLOB_ONLYDIR)
                            );

                            if ($subDirs) {
                                foreach ($subDirs as $i => $subDir) {
                                    $subDirName = basename($subDir);

                                    if (
                                        in_array($subDirName, $arExcludeSubDirNames)
                                        || in_array($subDir, $this->sd['exclude_subdirs'])
                                    ) {
                                        unset($subDirs[$i]);
                                        continue;
                                    }

                                    // exclude vendors
                                    if (preg_match('/\/vendors?\//mis', $subDir, $tmp)) {
                                        unset($subDirs[$i]);
                                        continue;
                                    }

                                    // exclude bitrix modules (without dot)
                                    if (
                                        $options['full_scan']
                                        && preg_match('/('.trim(static::BX_ROOT, '/').'|local)\/modules\/([^\/]+)$/mis', $subDir, $tmp)
                                        && false === strpos($tmp[2], '.')
                                    ) {
                                        unset($subDirs[$i]);
                                        continue;
                                    }
                                }

                                if ($subDirs) {
                                    $this->sd['remaining_dirs'] = array_merge($subDirs, $this->sd['remaining_dirs']);
                                }
                            }

                            if ($phpFiles) {
                                foreach ($phpFiles as $phpFile) {
                                    ++$this->sd['cnt_checked_files'];

                                    $content = $this->getModifiedContent($phpFile);
                                    if (false !== $content) {
                                        $this->sd['finded_files'][$phpFile] = 'M';
                                    }
                                }
                            }
                        }
                    }
                }

                $stage = $this->getStage();
            }
        }
    }

    public function isPreviewAction()
    {
        return 'previewAction' === $this->getActionMethod();
    }

    public function fixAction()
    {
        $this->checkBitrix();

        $options = $this->getOptions();

        $step = $this->getStep();
        if ($step > static::MAX_STEP) {
            throw new Exception('Превышено максимальное число шагов ('.static::MAX_STEP.').<br />Проверьте отсутствие зацикленности в структуре папок.');
        }

        if (!$step) {
            $remainingFiles = $options['files'];

            $zipPath = $_SERVER['DOCUMENT_ROOT'].'/upload/'.static::UPLOAD_ZIP_DIR.'/'.$this->generateZipName().'_'.date('dmY', time()).'.zip';

            $this->sd = [
                'error' => '',
                'options' => $options,
                'cnt_checked_files' => $this->sd['cnt_checked_files'],
                'finded_files' => $this->sd['finded_files'],
                'fixed_files' => [],
                'zip_path' => $zipPath,
                'remaining_files' => $remainingFiles,
                'unlink_files' => [
                    '/ajax/error_log_logic.php',
                    '/ajax/js_error.php',
                    '/ajax/js_error.txt',
                ],
                'patch_files' => [
                    '/form/index.php' => [
                        [
                            '<?elseif($form_id == \'TABLES_SIZE\'):?>',
                        ],
                        '<?// Don\'t use it! Update the module!?>'."\n".'<?elseif($form_id == \'TABLES_SIZE\' && false):?>',
                    ],
                    '/ajax/form.php' => [
                        [
                            '<?elseif($form_id === \'TABLES_SIZE\'):?>',
                            '<?elseif($form_id == \'TABLES_SIZE\'):?>',
                        ],
                        '<?// Don\'t use it! Update the module!?>'."\n".'<?elseif($form_id == \'TABLES_SIZE\' && false):?>',
                    ],
                ],
                'stages' => [
                    'unserialize' => [],
                    'finish' => [],
                ],
            ];

            if ($options['create_zip']) {
                $this->openZip();
            }
        } else {
            $stage = $this->getStage();
            $maxtime = time() + static::MAX_STEP_TIME;

            if ($options['create_zip']) {
                $this->openZip();
            }

            while (
                time() <= $maxtime
                && !$this->isFinishStage()
            ) {
                if ('unserialize' === $stage) {
                    if (empty($this->sd['remaining_files'])) {
                        $this->nextStage();
                    } else {
                        $file = array_shift($this->sd['remaining_files']);
                        if ($file) {
                            $file = trim($file);
                            if (
                                strlen($file)
                                && isset($this->sd['finded_files'][$file])
                            ) {
                                if ('D' === $this->sd['finded_files'][$file]) {
                                    // !!! do not create .back for this type !!!
                                    // if ($options['create_back']) {
                                    //     $backFile = $this->createBack($file);
                                    // }

                                    if ($options['create_zip']) {
                                        $this->add2Zip(dirname($file));
                                        $this->add2Zip($file);
                                    }

                                    @unlink($file);
                                    $this->sd['fixed_files'][$file] = 'D';
                                } elseif ('M' === $this->sd['finded_files'][$file]) {
                                    $content = $this->getModifiedContent($file);
                                    if (false !== $content) {
                                        if ($options['create_back']) {
                                            $backFile = $this->createBack($file);
                                        }

                                        if ($options['create_zip']) {
                                            $this->add2Zip(dirname($file));
                                            $this->add2Zip($file);
                                        }

                                        @file_put_contents($file, $content);
                                        $this->sd['fixed_files'][$file] = 'M';
                                    }
                                }
                            }
                        }
                    }
                }

                $stage = $this->getStage();
            }
        }
    }

    public function getModifiedContent($file)
    {
        $file = trim($file);
        if (strlen($file)) {
            $content = @file_get_contents($file);
            if (false !== $content && strlen($content)) {
                $bModified = false;
                $safe = [];

                if ($this->sd['patch_files']) {
                    foreach ($this->sd['patch_files'] as $patchFile => $replace) {
                        if (
                            false !== strpos($file, $patchFile)
                            && $replace
                            && is_array($replace)
                        ) {
                            if (
                                '/ajax/form.php' === $patchFile
                                || '/form/index.php' === $patchFile
                            ) {
                                if (false !== strpos($content, 'file_exists($url_sizes)')) {
                                    break;
                                }
                            }

                            foreach ($replace[0] as $r) {
                                if (false !== strpos($content, $r)) {
                                    $content = str_replace($replace[0], $replace[1], $content);
                                    $bModified = true;

                                    break;
                                }
                            }

                            break;
                        }
                    }
                }

                if (false !== strpos($content, 'unserialize')) {
                    $basePattern = '(?<!->|->\s|::|::\s|function\s|function\s\s|[$_a-z0-9])unserialize\s*[\(].*?[\)]';
                    $pattern = $basePattern;

                    while (preg_match('/'.$pattern.'/msi', $content, $match)) {
                        $expression = $match[0];

                        if ($this->testCorrectFuncExpression($expression)) {
                            if (
                                false === strpos($expression, 'allowed_classes')
                                && !preg_match('/unserialize\s*[\(].*?[,]\s*[\$][^\)]*?[\)]/msi', $expression, $tmp)
                            ) {
                                $newmatch = preg_replace('/(.*?)[\)]$/', '$1, [\'allowed_classes\' => false])', $expression);
                                $content = preg_replace('/'.$pattern.'/msi', $newmatch, $content, 1);
                                $bModified = true;
                            } else {
                                $safeKey = '##_SAFE_'.count($safe).'##';
                                $safe[$safeKey] = $expression;
                                $content = preg_replace('/'.$pattern.'/msi', $safeKey, $content, 1);
                            }

                            $pattern = $basePattern;
                        } else {
                            $pattern .= '.*?[\)]';

                            continue;
                        }
                    }

                    if ($safe) {
                        $content = str_replace(array_keys($safe), array_values($safe), $content);
                    }
                }

                return $bModified ? $content : false;
            }
        }

        return false;
    }

    public function isFixAction()
    {
        return 'fixAction' === $this->getActionMethod();
    }

    public function getFindedFiles()
    {
        if (
            $this->sd
            && is_array($this->sd)
            && isset($this->sd['finded_files'])
            && is_array($this->sd['finded_files'])
        ) {
            return $this->sd['finded_files'];
        }

        return [];
    }

    public function getFixedFiles()
    {
        if (
            $this->sd
            && is_array($this->sd)
            && isset($this->sd['fixed_files'])
            && is_array($this->sd['fixed_files'])
        ) {
            return $this->sd['fixed_files'];
        }

        return [];
    }

    public function getCntCheckedFiles()
    {
        if (
            $this->sd
            && is_array($this->sd)
            && isset($this->sd['cnt_checked_files'])
        ) {
            return intval($this->sd['cnt_checked_files']);
        }

        return 0;
    }

    public function getError()
    {
        if (
            $this->sd
            && is_array($this->sd)
            && isset($this->sd['error'])
            && is_string($this->sd['error'])
        ) {
            return $this->sd['error'];
        }

        return '';
    }

    public function hasError()
    {
        return strlen($this->getError()) > 0;
    }

    public function setError($message)
    {
        if (
            $this->sd
            && is_array($this->sd)
        ) {
            $this->sd['error'] = $message;
            $this->sd['stages'] = [
                'finish' => [],
            ];
        }
    }

    public function getStage()
    {
        if (
            $this->sd
            && is_array($this->sd)
            && isset($this->sd['stages'])
            && is_array($this->sd['stages'])
        ) {
            return key($this->sd['stages']);
        }

        return '';
    }

    public function nextStage()
    {
        if (
            $this->sd
            && is_array($this->sd)
            && isset($this->sd['stages'])
            && is_array($this->sd['stages'])
        ) {
            array_shift($this->sd['stages']);
        }
    }

    public function isFinishStage()
    {
        $stage = $this->getStage();

        return !$stage || 'finish' === $stage;
    }

    protected function testCorrectFuncExpression($expression)
    {
        if ($expression) {
            return preg_match_all('/[\(]/', $expression) == preg_match_all('/[\)]/', $expression);
        }

        return false;
    }

    protected function createBack($filePath)
    {
        if (
            $filePath
            && @file_exists($filePath)
            && !@is_dir($filePath)
        ) {
            $basename = basename($filePath);
            $dirname = dirname($filePath);
            $backFile = $dirname.'/_'.$basename.'.back';

            @unlink($backFile);
            @copy($filePath, $backFile);

            return $backFile;
        }

        return '';
    }

    public function getZipPath()
    {
        if (
            $this->sd
            && is_array($this->sd)
            && isset($this->sd['zip_path'])
            && is_string($this->sd['zip_path'])
        ) {
            return $this->sd['zip_path'];
        }

        return '';
    }

    protected function openZip()
    {
        $zipPath = $this->sd['zip_path'];

        @mkdir(dirname($zipPath), static::DIR_PERMISSIONS, true);
        $this->zip = new ZipArchive();
        if (true !== $this->zip->open($zipPath, ZipArchive::CREATE)) {
            $this->sd['options']['create_zip'] = false;
            $this->sd['zip_path'] = '';
            $this->zip = null;
        }
    }

    protected function generateZipName($length = 6)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $filename = '';

        $filename .= $chars[rand(0, 51)];
        for ($i = 0; $i < $length; ++$i) {
            $filename .= $chars[rand(0, strlen($chars) - 1)];
        }

        return $filename;
    }

    protected function add2Zip($filePath)
    {
        if ($this->zip) {
            if (@file_exists($filePath)) {
                $basename = basename($filePath);
                $dirname = str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname($filePath));
                $fileInZip = (strlen($dirname) ? str_replace('!#_#/', '', '!#_#'.$dirname).'/' : '').$basename;

                if (@is_dir($filePath)) {
                    $this->zip->addEmptyDir($this->encodeToZip($fileInZip));
                } else {
                    // don't use addFile() because it adds files to zip->close() when the contents of the file have already been modified
                    // if (!$this->zip->addFile($filePath, $this->encodeToZip($fileInZip))) {
                    // try another way
                    $handle = @fopen($filePath, 'rb');
                    if ($handle) {
                        $contents = @fread($handle, @filesize($filePath));
                        if (false !== $contents) {
                            $this->zip->addFromString($this->encodeToZip($fileInZip), $contents);
                        }

                        @fclose($handle);
                    }
                    // }
                }
            }
        }
    }

    protected function closeZip()
    {
        if (
            $this->zip
            && is_object($this->zip)
        ) {
            if ('ZipArchive' === get_class($this->zip)) {
                $this->zip->close();
            }

            unset($this->zip);
            $this->zip = null;
        }
    }

    protected function encodeToZip($str)
    {
        return iconv($this->isWindows() ? 'cp1251' : 'utf-8', 'CP866//IGNORE', $str);
    }

    public function cleanAction()
    {
        @unlink(__FILE__);
    }
}

// main
error_reporting(E_ERROR | E_PARSE);
set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

$fixit = Fixit::getInstance();
$fixit->execute();
$options = $fixit->getOptions();
$cntCheckedFiles = $fixit->getCntCheckedFiles();
$findedFiles = $fixit->getFindedFiles();
$fixedFiles = $fixit->getFixedFiles();

if ($fixit->isFixAction()) {
    if (
        $options['self_deletion']
        && $fixit->isFinishStage()
    ) {
        $fixit->cleanAction();
    }
}

$disabledForm = $fixit->isFinishStage() || $fixit->hasError();
?><!DOCTYPE html>
<html xml:lang="ru" lang="ru" data-bs-theme="dark">
	<head>
		<meta name="robots" content="noindex, nofollow"/>
		<meta http-equiv="X-UA-Compatible" content="IE=edge" />
		<meta name="viewport" content="user-scalable=no, initial-scale=1.0, maximum-scale=1.0, width=device-width">
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
		<title>Обновление безопасности</title>
		<style type="text/css">
		</style>
	</head>
	<body>
		<div class="container-fluid">
			<div class="page-header">
				<h1>Обновление безопасности</h1>
			</div>

			<div id="content" class="row">
				<div class="col">
                    <form action="" method="POST" class="m-0 p-0">
                        <?php if ($fixit->hasError()) { ?>
                            <div class="alert alert-danger d-inline-block" role="alert">
                                <?php echo $fixit->getError(); ?>
                            </div><br >
                        <?php }?>

                        <?php if (file_exists(__FILE__)) { ?>
                            <?php if (!$fixit->isMainAction() || !$fixit->hasError()) { ?>
                                <div class="border border-secondary p-3 rounded d-inline-block">
                                    <input type="hidden" name="session_id" value="<?php echo session_id(); ?>" />
                                    <input type="hidden" name="step" value="0" />

                                    <div class="mb-3 form-check">
                                        <input type="hidden" value="0" name="options[full_scan]" />
                                        <input type="checkbox" class="form-check-input" id="options--full_scan" value="1" name="options[full_scan]" <?php echo $options['full_scan'] ? ' checked' : ''; ?> <?php echo $disabledForm ? '' : ' disabled'; ?> />
                                        <label class="form-check-label" for="options--full_scan">Расширенный поиск, включая код сторонних разработчиков</label>
                                    </div>

                                    <button type="submit" class="btn btn-success me-md-2" name="action" value="preview" <?php echo $disabledForm ? '' : ' disabled'; ?> >Сканировать</button>
                                    <button type="submit" class="btn btn-warning" name="action" value="clean" <?php echo $disabledForm ? '' : ' disabled'; ?> >Удалить скрипт</button>

                                    <?php if ((!$fixit->isMainAction() && !$fixit->isFixAction()) && $fixit->isFinishStage()) {?>
                                        <div class="mt-3">
                                            <div class="mb-3 form-check">
                                                <input type="hidden" value="0" name="options[create_back]" <?php echo $disabledForm ? '' : ' disabled'; ?> />
                                                <input type="checkbox" class="form-check-input" id="options--create_back" value="1" name="options[create_back]" <?php echo $options['create_back'] ? ' checked' : ''; ?> <?php echo $disabledForm ? '' : ' disabled'; ?> />
                                                <label class="form-check-label" for="options--create_back">Создать .back для измененных файлов</label>
                                            </div>

                                            <div class="mb-3 form-check">
                                                <input type="hidden" value="0" name="options[create_zip]" <?php echo $disabledForm ? '' : ' disabled'; ?> />
                                                <input type="checkbox" class="form-check-input" id="options--create_zip" value="1" name="options[create_zip]" <?php echo $options['create_zip'] ? ' checked' : ''; ?> <?php echo $disabledForm ? '' : ' disabled'; ?> />
                                                <label class="form-check-label" for="options--create_zip">Создать .zip архив измененных файлов</label>
                                            </div>

                                            <div class="mb-3 form-check">
                                                <input type="hidden" value="0" name="options[self_deletion]" <?php echo $disabledForm ? '' : ' disabled'; ?> />
                                                <input type="checkbox" class="form-check-input" id="options--self_deletion" value="1" name="options[self_deletion]" <?php echo $options['self_deletion'] ? ' checked' : ''; ?> <?php echo $disabledForm ? '' : ' disabled'; ?> />
                                                <label class="form-check-label" for="options--self_deletion">Удалить скрипт после выполнения</label>
                                            </div>

                                            <button type="submit" class="btn btn-primary me-md-2" name="action" value="fix" <?php echo $disabledForm ? '' : ' disabled'; ?> >Исправить выбранные</button>
                                        </div>
                                    <?php }?>                                
                                </div><br>
                            <?php }?>
                        <?php } else {?>
                            <div class="alert alert-warning d-inline-block" role="alert">
                                Скрипт удален!
                            </div>
                        <?php }?>

                        <?php if (!$fixit->isMainAction()) {?>
                            <div class="mt-3">
                                <?php if ($fixit->isFinishStage()) {?>
                                    <div class="alert alert-success d-inline-block" role="alert">
                                        <?php echo $fixit->isPreviewAction() ? 'Сканирование ' : 'Исправление '; ?> завершено!
                                    </div>
                                <?php } else {?>
                                    <div class="alert alert-primary d-inline-block" role="alert">
                                        <?php echo $fixit->isPreviewAction() ? 'Сканирование ' : 'Исправление '; ?> выполняется...
                                    </div>
                                    <?php $fixit->nextStep(); ?>
                                <?php }?>

                                <div>Проверено файлов: <b><?php echo $cntCheckedFiles; ?></b></div>
                                <div>Потенциально небезопасных файлов: <b><?php echo count($findedFiles); ?></b></div>
                                <?php if ($fixit->isFixAction()) { ?>
                                    <div>Исправлено файлов: <b><?php echo count($fixedFiles); ?></b></div>
                                <?php }?>

                                <?php if (
                                    $fixit->isFixAction()
                                    && $fixit->isFinishStage()
                                    && $fixedFiles
                                    && $options['create_zip']
                                    && ($zipPath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $fixit->getZipPath()))
                                ) { ?>
                                    <div>Создан .zip архив: <b><a href="<?php echo $zipPath; ?>" target="_blank"><?php echo $zipPath; ?></a></b></div>
                                <?php }?>
                            </div>
                        <?php }?>

                        <div class="mt-3">
                            <?php if (
                                $fixit->isFinishStage()
                                && (
                                    (
                                        $fixit->isPreviewAction()
                                        && $findedFiles
                                    ) || (
                                        $fixit->isFixAction()
                                        && $fixedFiles
                                    )
                                )
                            ) {?>
                                <div class="mt-3">
                                    <?php if ($fixit->isFixAction()) {?>
                                        <?php foreach ($fixedFiles as $fixedFile => $type) { ?>
                                            <div class="mb-3 form-check">
                                                <label class="form-check-label"><?php echo str_replace($_SERVER['DOCUMENT_ROOT'], '', $fixedFile); ?>&nbsp;&nbsp;<small><b><?php echo $type; ?></b></small></label>
                                            </div>
                                        <?php }?>
                                    <?php } else {?>
                                        <div class="mb-3">
                                            <span id="select_all" class="btn btn-light btn-sm">Отметить все</span>
                                            <span id="unselect_all" class="btn btn-light btn-sm" style="display:none;">Снять все</span>
                                            <script>
                                            (function() {
                                                document.querySelector('#select_all').addEventListener('click', function(e) {
                                                    const target = e.target;

                                                    if (target) {
                                                        target.style.display = 'none';
                                                        document.querySelector('#unselect_all').style.display = 'inline-block';

                                                        target.closest('form').querySelectorAll('[name="options[files][]"]').forEach((checkbox) => {
                                                            checkbox.checked = true;
                                                        });
                                                    }
                                                });

                                                document.querySelector('#unselect_all').addEventListener('click', function(e) {
                                                    const target = e.target;

                                                    if (target) {
                                                        target.style.display = 'none';
                                                        document.querySelector('#select_all').style.display = 'inline-block';

                                                        target.closest('form').querySelectorAll('[name="options[files][]"]').forEach((checkbox) => {
                                                            checkbox.checked = false;
                                                        });
                                                    }
                                                });
                                            })();
                                            </script>
                                        </div>

                                        <?php $i = 0; ?>
                                        <?php foreach ($findedFiles as $findedFile => $type) { ?>
                                            <div class="mb-3 form-check">
                                                <input type="checkbox" class="form-check-input" id="options--files[<?php echo $i; ?>]" value="<?php echo $findedFile; ?>" name="options[files][]" />
                                                <label class="form-check-label" for="options--files[<?php echo $i; ?>]"><?php echo str_replace($_SERVER['DOCUMENT_ROOT'], '', $findedFile); ?>&nbsp;&nbsp;<small><b><?php echo $type; ?></b></small></label>
                                            </div>
                                            <?php ++$i; ?>
                                        <?php }?>
                                    <?php }?>
                                </div>
                            <?php }?>
                        </div>
                    </form>

                    <div class="mt-3">
                        <small class="alert alert-light d-inline-block" role="alert">
                            В целях безопасности поддерживайте актуальные версии модулей, для этого должна быть <a href="https://aspro.ru/shop/" target="_blank">активная лицензия</a>
                        </small>
                    </div>
				</div>
			</div>
		</div>
	</body>
</html>
