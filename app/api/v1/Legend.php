<?php
namespace app\api\v1;

use \app\conf\App;
use \app\inc\Input;

class Legend extends \app\inc\Controller
{
    private $legendArr;

    function __construct()
    {
        $path = App::$param['path'] . "/app/wms/mapfiles/";
        if (\app\inc\Input::get("l")) {
            $layerNames = explode(";", \app\inc\Input::get("l"));
            foreach ($layerNames as $layerName) {
                $splitName = explode(".", $layerName);
                $mapFile = \app\inc\Input::getPath()->part(5) . "_" . $splitName[0] . ".map";
                $map = ms_newMapobj($path . $mapFile);

                $layer = $map->getLayerByName($layerName);
                if ($layer) {
                    $this->legendArr[$layerName]['title'] = $layer->getMetaData("wms_title");
                    for ($i = 0; $i < $layer->numclasses; $i++) {
                        $class = $layer->getClass($i);

                        $icon = $class->createLegendIcon(17, 17);
                        ob_start();
                        $icon->saveImage("", $map);
                        $data = base64_encode(ob_get_clean());
                        $this->legendArr[$layerName]['classes'][$i]['img'] = $data;

                        $this->legendArr[$layerName]['classes'][$i]['name'] = $class->name;
                        $this->legendArr[$layerName]['classes'][$i]['expression'] = $class->getExpressionString();
                    }
                }
            }
        }
    }

    public function get_html()
    {
        $html = "";
        if (is_array($this->legendArr)) {
            foreach ($this->legendArr as $layer) {
                //$html .= "<div class=\"legend legend-container\"><div class=\"legend legend-header\"><b>" . $layer['title'] . "<b></div>";
                $html .= "<table class=\"legend legend-body: 10px\">";
                if (is_array($layer['classes'])) {
                    foreach ($layer['classes'] as $class) {
                        if ($class['name']) {
                            $html .= "<tr><td class=\"legend img\"><img src=\"data:image/png;base64, {$class['img']}\"></td>";
                            $html .= "<td class=\"legend legend-text\">" . $class['name'] . "</td></tr>";
                        }
                    }
                }
                $html .= "</table></div>";
            }
        }
        $response['html'] = $html;
        return $response;
    }

    public function get_json()
    {
        $json = array();
        $classes = array();
        if (is_array($this->legendArr)) {
            foreach ($this->legendArr as $key=>$layer) {
                {
                    if (is_array($layer['classes'])) {
                        foreach ($layer['classes'] as $class) {
                            $classes[] = array(
                                "name"=>$class['name'],
                                "expression"=>$class['expression'],
                                "img"=>$class['img']
                            );
                        }
                    }
                    $json[] = array("id"=>$key,"classes"=>$classes);
                    $classes = array();
                }
            }
        }
        return $json;
    }
    public function get_quantile(){
        $this->class = new \app\models\Classification(Input::get("l"));
        return $this->class->createQuantile(Input::get("f"),Input::get("n"),"#".Input::get(s),"#".Input::get(e),null,false);
    }}