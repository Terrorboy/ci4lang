<?php

namespace ci4lang\Ci4lang;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Factory\RendererFactory;
use Jfcherng\Diff\Renderer\RendererConstant;

use CodeIgniter\CLI\CLI;

class Ci4langClass
{
    private string $lang = 'ko';
    private string $ciPath;
    private string $langPath;
    private string $origin;
    private string $target;

    public function __construct(string $language='ko')
    {
        helper('filesystem');
        $this->ciPath = SYSTEMPATH.'Language';
        $this->langPath = __DIR__.'/../translations/Language';
        $this->lang = $language;
        $this->origin = $this->ciPath.'/en';
        $this->target = $this->langPath.'/'.$this->lang;
    }

    public function check()
    {
        if (is_dir($this->target) === false) {
            dd('There is no target translation pack.');
            return;
        }
        $originMap = $this->variablesMapping($this->origin, 'origin');
        $targetMap = $this->variablesMapping($this->target);
        $diff = $this->mapDiffer($originMap, $targetMap);
        $this->diffTable($diff);
    }

    public function cli()
    {
        if (is_dir($this->target) === false) {
            CLI::write('There is no target translation pack.', 'light_gray', 'red');
            exit;
        }

        CLI::write('Start '.$this->lang.' check...', 'black', 'green');
        $originMap = $this->variablesMapping($this->origin, 'origin');
        $targetMap = $this->variablesMapping($this->target);
        $diff = $this->mapDiffer($originMap, $targetMap);

        $cliThead = ['file', 'key'];
        $cliTbody = [];
        $maxlen = max(array_map('strlen', array_keys($diff)));
        foreach ($diff as $key => $value) {
            $cliTbody[] = [$key, CLI::wrap(implode(', ', array_keys($value)), 50, $maxlen+5)];
        }
        CLI::table($cliTbody);
    }

    private function variablesMapping(string $path='', string $type='target')
    {
        $map = [];
        foreach ((directory_map($path)??[]) as $file) {
            $map[$file] = $this->getFileDocBlock($path.'/'.$file, $type);
        }
        return $map;
    }

    private function getFileDocBlock(string $file=null, string $type='target')
    {
        if ($file === null) {
            return [];
        }
        $docBlockTmp = [];
        $docBlock = [];
        if (file_exists($file) === false) {
            return [];
        }
        $fileContent = require_once($file);
        $tokens = token_get_all(file_get_contents($file));
        $deepArray = [];
        foreach ($tokens as $k=>$v) {
            if (empty($v) || empty($v[2])) {
                continue;
            }
            if (in_array(token_name($v[0]), ['T_CONSTANT_ENCAPSED_STRING', 'T_COMMENT']) === false) {
                continue;
            }
            $valuesKey = str_replace(['"', '\''], '', $v[1]);
            if (is_array($fileContent[$valuesKey]??'')) {
                $findArray = array_filter($fileContent[$valuesKey], function ($array) {
                    return (is_array($array));
                });
                $deepArray = array_merge($deepArray, $this->deepArrayKeys($fileContent[$valuesKey]));
                continue;
            }
            if (in_array($valuesKey, $deepArray)) {
                continue;
            }
            if (token_name($v[0]) === 'T_CONSTANT_ENCAPSED_STRING' && empty($docBlockTmp[$v[2]])) {
                $docBlockTmp[$v[2]] = [
                    'value' => $v[1],
                ];
            }

            if ($type == 'target') {
                if (token_name($v[0]) === 'T_COMMENT') {
                    $docBlockTmp[$v[2]]['comment'] = $v[1];
                }
            } else {
                if (token_name($v[0]) === 'T_CONSTANT_ENCAPSED_STRING') {
                    $docBlockTmp[$v[2]]['comment'] = $v[1];
                }
            }
        }
        foreach (($docBlockTmp??[]) as $k=>$v) {
            if (
                array_key_exists('value', $v) === false ||
                array_key_exists('comment', $v) === false
            ) {
                continue;
            }
            $name = str_replace(["'", '"'], '', $v['value']);
            $docBlock[$name] = [
                'value'=>trim(str_replace(['//', "'", '"'], '', ($v['comment']??''))),
                'line'=>$k,
            ];
        }

        return $docBlock;
    }

    private function deepArrayKeys(array|string $data)
    {
        $keys = [];
        if (is_array($data)) {
            foreach ($data as $k=>$v) {
                if (is_array($v)) {
                    $keys[] = $k;
                    $this->deepArrayKeys($v);
                }
            }
        }
        return $keys;
    }

    private function mapDiffer(array $origin, array $target)
    {
        $diff = [];
        $merges = array_merge_recursive($target, $origin);
        foreach (($merges??[]) as $fileName=>$data) {
            $diff[$fileName] = [];
            foreach (($data??[]) as $valuesKey=>$values) {
                $old = $target[$fileName][$valuesKey]??[];
                $new = $origin[$fileName][$valuesKey]??[];
                if (($old <=> $new) === 0) {
                    continue;
                }
                if (is_array($merges[$fileName][$valuesKey]['value']??'') === false) {
                    if ((count($old) <=> count($new)) === 0) {
                        continue;
                    }
                }
                $diff[$fileName][$valuesKey] = [
                    'old'=>(count($old) > 0?$old['value']:'<span class="null_values">null</span>'),
                    'new'=>(count($new) > 0?$new['value']:'<span class="null_values">null</span>'),
                ];
            }
        }
        foreach (($merges??[]) as $fileName=>$data) {
            if (count($diff[$fileName]) == 0) {
                unset($diff[$fileName]);
            }
        }
        return $diff;
    }

    private function diffTable(array $diff=[])
    {
        $diffStyle = \Jfcherng\Diff\DiffHelper::getStyleSheet();
        echo '
            <style>
                /* https://nanati.me/html_css_table_design/ */
                table {
                    width: 99%;
                    border-collapse: separate;
                    border-spacing: 0;
                    text-align: left;
                    line-height: 1.5;
                    border-top: 1px solid #ccc;
                    border-left: 1px solid #ccc;
                    margin : 20px 10px;
                }
                table th {
                    padding: 10px;
                    font-weight: bold;
                    vertical-align: top;
                    border-right: 1px solid #ccc;
                    border-bottom: 1px solid #ccc;
                    border-top: 1px solid #fff;
                    border-left: 1px solid #fff;
                    background: #eee;
                }
                table td {
                    padding: 10px;
                    vertical-align: top;
                    border-right: 1px solid #ccc;
                    border-bottom: 1px solid #ccc;
                }

                /* etc */
                .en_value {
                    color: #198754;
                }
                .null_values {
                    color: #ff0000;
                }

                /* diff style */
                '.$diffStyle.'
                .diff-wrapper.diff {
                    width: -webkit-fill-available;
                }
            </style>
        ';
        foreach (($diff??[]) as $fileName=>$content) {
            echo '<h4>'.$fileName.'</h4>';
            echo '
                <table style="border:1px solid #ccc;">
                    <colgroup>
                        <col width="150">
                        <col width="200">
                        <col width="200">
                        <col>
                    </colgroup>
                    <thead>
                        <tr>
                            <th>key</th>
                            <th>en</th>
                            <th>'.$this->lang.'</th>
                            <th>diff</th>
                        </tr>
                    </thead>
                    <tbody>
            ';
            foreach (($content??[]) as $variables=>$values) {
                $jsonResult = DiffHelper::calculate(strip_tags($values['old']??''), strip_tags($values['new']??''), 'Json');
                $htmlRenderer = RendererFactory::make('Combined', [
                    'detailLevel'=>'line',
                    'lineNumbers'=>false,
                    'showHeader'=>false,
                ]);
                $result = $htmlRenderer->renderArray(json_decode($jsonResult, true));
                echo '
                    <tr>
                        <th>'.$variables.'</th>
                        <td class="en_value">'.$values['new'].'</td>
                        <td>'.$values['old'].'</td>
                        <td>'.$result.'</td>
                    </tr>
                ';
            }
            echo '
                    </tbody>
                </table>
            ';
        }
    }
}
