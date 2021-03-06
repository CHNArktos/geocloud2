<?php
namespace app\controllers\upload;

use \app\conf\App;
use \app\conf\Connection;
use \app\inc\Input;
use \app\inc\Model;
use \app\controllers\Tilecache;

/**
 * Class Processqgis
 * @package app\controllers\upload
 */
class Processqgis extends \app\inc\Controller
{
    private $table;
    private $layer;
    private $sridStr;

    function __construct()
    {
        $this->table = new \app\models\Table("settings.geometry_columns_join");
        $this->layer = new \app\models\Layer();
        $this->sridStr = "EPSG:4326 EPSG:3857 EPSG:900913 EPSG:25832";
    }

    public function get_index()
    {
        $file = Input::get("file");
        $qgs = @simplexml_load_file(App::$param['path'] . "/app/tmp/" . Connection::$param["postgisdb"] . "/__qgis/" . $file);
        $arrT = [];
        $arrG = [];
        $arrN = [];
        $wmsNames = [];
        $wmsSrids = [];
        $treeOrder = [];
        $createWms = Input::get("createWms") == "true" ? true : false;
        $createComp = Input::get("createComp") == "true" ? true : false;

        if (!$qgs) {
            return array("success" => false, "code" => 400, "message" => "Could not read qgs file");
        }

        foreach ($qgs->projectlayers[0]->maplayer as $maplayer) {

            $provider = (string)$maplayer->provider;

            switch ($provider) {

                case "postgres":
                    $dataSource = (string)$maplayer->datasource;
                    $layerName = (string)$maplayer->layername;
                    $newDataSource = preg_replace("/host=\S*/", "host=" . Connection::$param["postgishost"], $dataSource, 1);
                    preg_match("/table=\S*/", $dataSource, $matches);
                    $maplayer->datasource = $newDataSource;
                    preg_match_all("/\"(.*?)\"/", $matches[0], $t);
                    $arrT[] = $t;
                    preg_match_all("/\((.*?)\)/", $dataSource, $g);
                    $arrG[] = $g;
                    $maplayer->layername = $t[1][0] . "." . $t[1][1];
                    $arrN[] = $maplayer->layername;
                    $maplayer->title = (string)$maplayer->title ?: $layerName;
                    break;

                case "WFS":
                    $TYPENAME = "";
                    $dataSource = (string)$maplayer->datasource;
                    $layerName = (string)$maplayer->layername;
                    $parsed = parse_url($dataSource);
                    $db = explode("/", $parsed["path"])[2];

                    $split = explode("@", $db);
                    if (sizeof($split) > 1) {
                        $db = $split[1];
                    }

                    $schema = explode("/", $parsed["path"])[3];

                    parse_str($parsed["query"]);
                    $table = explode(":", $TYPENAME)[1];

                    $fullTable = $schema . "." . $table;

                    $rec = $this->layer->getAll(null, $fullTable, true);
                    $pkey = $rec["data"][0]["pkey"];
                    $srid = $rec["data"][0]["srid"];
                    $type = $rec["data"][0]["type"];
                    $f_geometry_column = $rec["data"][0]["f_geometry_column"];

                    $spatialRefSys = new \app\models\Spatial_ref_sys();
                    $spatialRefSysRow = $spatialRefSys->getRowBySrid($srid);

                    $proj4text = $spatialRefSysRow["data"]["proj4text"];

                    $arrT[] = array(1 => array($schema, $table));
                    $arrG[] = array(1 => array($f_geometry_column));
                    $arrN[] = $layerName;

                    $PGDataSource = "dbname={$db} host=" . Connection::$param["postgishost"] . " port=" . Connection::$param["postgisport"] . " user=" . Connection::$param["postgisuser"] . " password=" . Connection::$param["postgispw"] . " sslmode=disable key='{$pkey}' srid={$srid} type={$type} table=\"{$schema}\".\"{$table}\" ({$f_geometry_column}) sql=";

                    $maplayer->srs->spatialrefsys = "";
                    $maplayer->srs->spatialrefsys->proj4 = $proj4text;
                    $maplayer->srs->spatialrefsys->srid = $srid;
                    $maplayer->srs->spatialrefsys->authid = "EPSG:{$srid}";
                    $maplayer->provider = "postgres";
                    $maplayer->datasource = $PGDataSource;
                    //$maplayer->layername = $fullTable;
                    $maplayer->title = (string)$maplayer->title ?: $layerName;

                    break;

                case "wms":
                    if ($createWms) {
                        $layerName = Connection::$param["postgisschema"] . "." . Model::toAscii((string)$maplayer->layername, array(), "_");;
                        $srid = (string)$maplayer->srs->spatialrefsys->srid;
                        $wmsSrids[] = $srid;
                        $wmsNames[] = $layerName;
                        $maplayer->layername = $layerName;
                    }
                    break;
            }
        }

        // Get the layers in the right order according to QGIS layertree
        // =============================================================
        foreach ($qgs->{"layer-tree-group"}[0] as $group) {
            if ($group && $group[0]->attributes()) {
                $attrs = $group[0]->attributes();
                $id = strval($attrs['id']);
                foreach ($qgs->projectlayers[0]->maplayer as $maplayer) {
                    if ((string)$maplayer->id == $id) {
                        $treeOrder[] = (string)$maplayer->layername;
                    }
                }
            }
        }

        $path = App::$param['path'] . "/app/wms/qgsfiles/";
        $firstName = explode(".", $file)[0];
        $name = "parsed_" . Model::toAscii($firstName) . ".qgs";

        // Set QGIS wms source for PG layers
        // =================================

        for ($i = 0; $i < sizeof($arrT); $i++) {
            $tableName = $arrT[$i][1][0] . "." . $arrT[$i][1][1];
            $layerKey = $tableName . "." . $arrG[$i][1][0];
            $wmsLayerName = $arrN[$i];
            $layers[] = $layerKey;
            $url = "http://127.0.0.1/cgi-bin/qgis_mapserv.fcgi?map=" . $path . $name . "&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetMap&STYLES=&FORMAT=image/png&LAYER=" . $wmsLayerName . "&transparent=true&";
            $urls[] = $url;
            $data = new \stdClass;
            $data->_key_ = $layerKey;
            $data->wmssource = $url;
            $data->wmsclientepsgs = $this->sridStr;
            $data = array("data" => $data);
            $res = $this->table->updateRecord($data, "_key_");
            Tilecache::bust($tableName);
        }

        // Create new layers from QGIS WMS layer
        // =====================================

        for ($i = 0; $i < sizeof($wmsNames); $i++) {
            $tableName = $wmsNames[$i];
            $layerKey = $tableName . ".rast";
            $table = new \app\models\Table($tableName);
            $table->createAsRasterTable($wmsSrids[$i]);
            $url = "http://127.0.0.1/cgi-bin/qgis_mapserv.fcgi?map=" . $path . $name . "&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetMap&STYLES=&FORMAT=image/png&LAYER=" . $tableName . "&transparent=true&";
            $data = new \stdClass();
            $data->_key_ = $layerKey;
            $data->wmssource = $url;
            $data->wmsclientepsgs = $this->sridStr;
            $data = array("data" => $data);
            $res = $this->table->updateRecord($data, "_key_");
            Tilecache::bust($tableName);

        }

        // Create the composite map from all layers in qgs-file
        // ====================================================

        if ($createComp) {
            $tableName = Connection::$param["postgisschema"] . "." . Model::toAscii($firstName);
            $layerKey = $tableName . ".rast";
            $table = new \app\models\Table($tableName);
            $table->createAsRasterTable("4326");
            $url = "http://127.0.0.1/cgi-bin/qgis_mapserv.fcgi?map=" . $path . $name . "&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetMap&STYLES=&FORMAT=image/png&LAYER=" . implode(",", array_reverse($treeOrder)) . "&transparent=true&";
            $data = new \stdClass();
            $data->_key_ = $layerKey;
            $data->wmssource = $url;
            $data->wmsclientepsgs = "EPSG:4326 EPSG:3857 EPSG:900913 EPSG:25832";

            $data = array("data" => $data);
            $res = $this->table->updateRecord($data, "_key_");
            Tilecache::bust(Connection::$param["postgisschema"] . "." . $wmsNames[$i]);

        }


        // Write the new qgs-file
        // ======================

        @unlink($path . $name);
        $fh = fopen($path . $name, 'w');
        fwrite($fh, $qgs->asXML());
        fclose($fh);

        $res = json_decode($this->reload());
        $reloaded = $res->success ?: false;
        return array("success" => true, "message" => "Qgs file parsed", "reloaded" => $reloaded, "ch" => $path . $name, "layers" => $layers, "urls" => $urls);
    }

    public static function reload()
    {
        $res = \app\inc\Util::wget(App::$param["qgisServer"]["api"] . "/reload");
        return $res;
    }
}