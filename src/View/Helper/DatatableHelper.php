<?php
//@todo check width not working

declare(strict_types=1);

namespace CakeDC\Datatables\View\Helper;

use Cake\Utility\Inflector;
use Cake\View\Helper;
use Cake\View\View;
use Datatables\Exception\MissConfiguredException;
use InvalidArgumentException;

/**
 * Datatable helper
 *
 * @property \CakeDC\Datatables\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\UrlHelper $Url
 */
class DatatableHelper extends Helper
{
    /**
     * Default Datatable js library configuration.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'processing' => true,
        'serverSide' => true,
        // override to provide translations, @see https://datatables.net/examples/basic_init/language.html
        'language' => [],
        'lengthMenu' => [],
        'columnSearch' => true,
        // search configuration, false to hide
        //@todo make internal seach on/off based on this param
        //true => use default input search, false => use externalSearchInputId
        'search' => true,
        // set an external input to act as search
        //@todo make external seach based on this id
        'externalSearchInputId' => null,
        // extra fields to inject in ajax call, for example CSRF token, additional ids, etc
        'extraFields' => [],
        //draw callback function
        'drawCallback' => null,
        //complete callback function
        'onCompleteCallback' => null,

    ];

    private $columnSearchTemplate = <<<COLUMN_SEARCH_CONFIGURATION
        
        var api = this.api();

        // For each column
        api
            .columns()
            .eq(0)
            .each(function (colIdx) {
                // Set the header cell to contain the input element
                var cell = $('.filters th').eq(
                    $(api.column(colIdx).header()).index()
                );
                var title = $(cell).text();
                $(cell).html('<input type="text" style="width: 100%%;" placeholder="' + title + '" />');

                // On every keypress in this input
                $(
                    'input',
                    $('.filters th').eq($(api.column(colIdx).header()).index())
                )
                .off('keyup change')
                .on('keyup change', function (e) {
                    e.stopPropagation();

                    // Get the search value
                    $(this).attr('title', $(this).val());
                    var regexr = '({search})'; //$(this).parents('th').find('select').val();

                    var cursorPosition = this.selectionStart;
                    // Search the column for that value
                    api
                        .column(colIdx)
                        .search(
                            this.value != ''
                                ? regexr.replace('{search}', '(((' + this.value + ')))')
                                : '',
                            this.value != '',
                            this.value == ''
                        )
                        .draw();

                    $(this)
                        .focus()[0]
                        .setSelectionRange(cursorPosition, cursorPosition);
                });
            });
    COLUMN_SEARCH_CONFIGURATION;

    private $genericSearchTemplate = <<<GENERIC_SEARCH_CONFIGURATION
        $('#%s').on( 'keyup click', function () {
            $('#%s').DataTable().search(
                $('#%s').val()       
            ).draw();
        });
    GENERIC_SEARCH_CONFIGURATION;

    private $columnSearchHeaderTemplate = <<<COLUMN_SEARCH_HEADER_CONFIGURATION
        $('#%s thead tr')
            .clone(true)
            .addClass('filters')
            .appendTo('#%s thead');
    COLUMN_SEARCH_HEADER_CONFIGURATION;

    /**
     * Json template with placeholders for configuration options.
     *
     * @var string
     */
    private $datatableConfigurationTemplate = <<<DATATABLE_CONFIGURATION
        // API callback
        %s

        // Generic search         
        %s

        // Datatables configuration
        $(() => {     

            //@todo use configuration for multicolumn filters
            %s
            
            $('#%s').DataTable({
                orderCellsTop: true,
                fixedHeader: true,
                ajax: getData(),           
                //searching: false,
                processing: %s,
                serverSide: %s,
                //@todo: add option to select the paging type
                //pagingType: "simple",
                columns: [
                    %s
                ],
                columnDefs: [
                    %s                                
                ],            
                language: %s,
                lengthMenu: %s,
                drawCallback: %s,
                //drawCallback: function () {
                //},
                //@todo use configuration instead  
                initComplete: function () { 

                    //onComplete
                    %s

                    //column search                   
                    %s

                },
            });
        });
    DATATABLE_CONFIGURATION;

    /**
     * Other helpers used by DatatableHelper
     *
     * @var array
     */
    protected $helpers = ['Url', 'Html'];
    private $htmlTemplates = [
        'link' => '<a href="%s" target="%s">%s</a>',
    ];

    /**
     * @var string[]
     */
    private $dataKeys = [];

    /**
     * @var string
     */
    private $getDataTemplate;

    /**
     * @var string
     */
    private $configColumns;

    /**
     * @var string[]
     */
    private $definitionColumns = [];

    public function __construct(View $view, array $config = [])
    {
        if (!isset($config['lengthMenu'])) {
            $config['lengthMenu'] = [5, 10, 25, 50, 100];
        }
        parent::__construct($view, $config);
    }

    /**
     * set value of congig variable to value passed as param
     *
     * @param string|array $key key to write
     * @param string|array $value value to write
     * @param bool $merge merge
     */
    public function setConfigKey($key, $value = null, $merge = true)
    {
        $this->setConfig($key, $value);
    }

    /**
     * Build the get data callback
     *
     * @param string|array $url url to ajax call
     */
    public function setGetDataUrl($url = null)
    {
        $url = (array)$url;
        $url = array_merge($url, ['fullBase' => true, '_ext' => 'json']);
        $url = $this->Url->build($url);

        if (!empty($this->getConfig('extraFields'))) {
            $extraFields = $this->processExtraFields();
            //@todo change to async or anonymous js function
            $this->getDataTemplate = <<<GET_DATA
            function getData() {                
                return {
                    url:'{$url}',    
                    data: function ( d ) {
                            return $.extend( {}, d, {                            
                                $extraFields
                            });
                        }                            
                }      
            }    
            GET_DATA;
        } else {
            $this->getDataTemplate = <<<GET_DATA
                let getData = async () => {
                    let res = await fetch('{$url}')
                }
            GET_DATA;
        }
    }

    /**
     * Set columns definitions as orderable and sortable
     *
     * @param \Cake\Collection\Collection $dataDefinitions array of definitions in columns as orderable and sortable
     */
    public function setDefinitions(iterable $dataDefinitions)
    {
        $this->definitionColumns = $dataDefinitions;
    }

    /**
     * @param \Cake\Collection\Collection $dataKeys data keys to show in datatable
     */
    public function setFields(iterable $dataKeys)
    {
        if (empty($dataKeys)) {
            throw new InvalidArgumentException(__('Couldn\'t get first item'));
        }
        $this->dataKeys = $dataKeys;
    }

    public function setRowActions(?iterable $rowActions = null)
    {
        if ($rowActions) {
            $this->rowActions = $rowActions;

            return;
        }

        // default row actions
        $this->rowActions = [
            'name' => 'actions',
            'orderable' => 'false',
            'width' => '30px',
            //@todo: provide template customization for row actions default labels
            'links' => [
                [
                    'url' => ['action' => 'view', 'extra' => "/' + obj.id + '"],
                    'label' => '<li class="fas fa-search"></li>',
                ],
                [
                    'url' => ['action' => 'edit', 'extra' => "/' + obj.id + '"],
                    'label' => '<li class="fas fa-pencil-alt"></li>',
                ],
                //@todo: we'll need a way to produce postlinks
                [
                    'url' => ['action' => 'delete', 'extra' => "/' + obj.id + '"],
                    'label' => '<li class="far fa-trash-alt"></li>',
                ],
            ],
        ];
    }

    /**
     * Get Datatable initialization script with options configured.
     *
     * @param string $tagId
     * @return string
     */
    public function getDatatableScript(string $tagId): string
    {
        if (empty($this->getDataTemplate)) {
            $this->setGetDataUrl();
        }

        $this->processColumnRenderCallbacks();
        $this->processColumnDefinitionsCallbacks();
        $this->validateConfigurationOptions();

        if ($this->getConfig('columnSearch')) {
            $columnSearchTemplate = sprintf($this->columnSearchHeaderTemplate, $tagId, $tagId);
        } else {
            $columnSearchTemplate = '';
        }

        if (!$this->getConfig('search')) {
            $searchInput = $this->getConfig('externalSearchInputId');
            $searchTemplate = sprintf($this->genericSearchTemplate, $searchInput, $tagId, $searchInput);
        } else {
            $searchTemplate = '';
        }

        return sprintf(
            $this->datatableConfigurationTemplate,
            $this->getDataTemplate,
            $searchTemplate,
            $columnSearchTemplate,
            $tagId,
            $this->getConfig('processing') ? 'true' : 'false',
            $this->getConfig('serverSide') ? 'true' : 'false',
            $this->configColumns,
            $this->definitionColumns,
            json_encode($this->getConfig('language')),
            json_encode($this->getConfig('lengthMenu')),
            $this->getConfig('drawCallback') ? $this->getConfig('drawCallback') : 'null',
            $this->getConfig('onCompleteCallback') ? $this->getConfig('onCompleteCallback') : 'null',
            $this->getConfig('columnSearch') ? $this->columnSearchTemplate : '',
        );

    }

    /**
     * Validate configuration options for the datatable.
     *
     * @throws \Datatables\Exception\MissConfiguredException
     */
    protected function validateConfigurationOptions()
    {
        if (empty($this->dataKeys)) {
            throw new MissConfiguredException(__('There are not columns specified for your datatable.'));
        }

        if (empty($this->configColumns)) {
            throw new MissConfiguredException(__('Column renders are not specified for your datatable.'));
        }
    }

    /**
     * Loop extra fields to inject in ajax call to server
     */
    protected function processExtraFields()
    {
        $rows = [];
        foreach ($this->getConfig('extraFields') as $definition) {
            $parts = [];
            foreach ($definition as $key => $val) {
                $parts[] = "'{$key}': {$val}";
            }
            $rows[] = implode(',', $parts);
        }

        return implode(',', $rows);
    }

    /**
     * Loop columns definitions to set properties inside ColumnDefs as orderable or searchable
     */
    protected function processColumnDefinitionsCallbacks()
    {
        $rows = [];
        foreach ($this->definitionColumns as $definition) {
            $parts = [];
            foreach ($definition as $key => $val) {
                $parts[] = "'{$key}': {$val}";
            }
            $rows[] = '{' . implode(',', $parts) . '}';
        }
        $this->definitionColumns = implode(',', $rows);
    }

    /**
     * Loop columns and create callbacks or simple json objects accordingly.
     *
     * @todo: refactor into data object to define the column properties accordingly
     */
    protected function processColumnRenderCallbacks()
    {
        $processor = function ($key) {
            $output = '{';
            if (is_string($key)) {
                $output .= "data: '{$key}'";
            } else {
                if (!isset($key['name'])) {
                    return '';
                }
                $output .= "data: '{$key['name']}',";

                if (isset($key['links'])) {
                    $output .= "\nrender: function(data, type, obj) {";
                    $links = [];
                    foreach ((array)$key['links'] as $link) {
                        $links[] = $this->processActionLink($link);
                    }
                    $output .= 'return ' . implode("\n + ", $links);
                    $output .= '}';
                } elseif ($key['render'] ?? null) {
                    $output .= "render: {$key['render']}";
                } elseif ($key['orderable'] ?? null) {
                    $output .= "orderable: {$key['orderable']}";
                } elseif ($key['width'] ?? null) {
                    $output .= "width: '{$key['width']}'";
                }
            }
            $output .= '}';

            return $output;
        };
        $configColumns = array_map($processor, (array)$this->dataKeys);
        $configRowActions = $processor((array)$this->rowActions);
        $this->configColumns = implode(", \n", $configColumns);
        $this->configColumns .= ", \n" . $configRowActions;
    }

    /**
     * Format link with specified options from links array.
     *
     * @param array $link
     * @return string
     */
    protected function processActionLink(array $link): string
    {
        $urlExtraValue = '';

        if (is_array($link['url'])) {
            $urlExtraValue = $link['url']['extra'] ?? '';
            unset($link['url']['extra']);
        }

        if (!isset($link['target'])) {
            $link['target'] = '_self';
        }

        return "'" .
            sprintf(
                $this->htmlTemplates['link'],
                $this->Url->build($link['url']) . $urlExtraValue,
                $link['target'] ?: "' + {$link['target']} + '",
                $link['label'] ?: "' + {$link['value']} + '"
            )
            . "'";
    }

    /**
     * Get formatted table headers
     *
     * @param iterable|null $tableHeaders
     * @param bool $format
     * @param bool $translate
     * @param array $headersAttrsTr
     * @param array $headersAttrsTh
     * @return string
     */
    public function getTableHeaders(
        ?iterable $tableHeaders = null,
        bool $format = false,
        bool $translate = false,
        array $headersAttrsTr = [],
        array $headersAttrsTh = []
    ): string {
        $tableHeaders = $tableHeaders ?? $this->dataKeys;

        foreach ($tableHeaders as &$tableHeader) {
            if ($format) {
                $tableHeader = str_replace('.', '_', $tableHeader);
                $tableHeader = Inflector::humanize($tableHeader);
            }
            if ($translate) {
                $tableHeader = __($tableHeader);
            }
        }

        return $this->Html->tableHeaders($tableHeaders, $headersAttrsTr, $headersAttrsTh);
    }
}
