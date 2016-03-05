<?php
/**
 * PHP MongoDB Admin
 *
 * Administrate a MongoDB server:
 *
 *   * List, create and delete databases
 *   * List, create and delete collections
 *   * List, create, edit and delete documents
 *
 * Documents are editable with raw PHP code.
 *
 * http://github.com/jwage/php-mongodb-admin
 * http://www.twitter.com/jwage
 *
 * @author Jonathan H. Wage <jonwage@gmail.com>
 * @Theme Ted Veatch
 */

header('Pragma: no-cache');

$options = array(
    'connect' => true,
);

$readOnly = false;

if (!class_exists('MongoClient')) {
    die("Mongo support required. Install mongo pecl extension with 'pecl install mongo; echo \"extension=mongo.so\" >> php.ini'");
}
try
{
    $mongo = new MongoClient(getServer($server), $options);
} catch (MongoConnectionException $ex) {
    error_log($ex->getMessage());
    die("Failed to connect to MongoDB");
}

/**
 * Get the current MongoDB server.
 *
 * @param mixed $server
 * @return string $server
 */
function getServer($server)
{
    if (is_array($server)) {
        return (isset($_COOKIE['mongo_server']) && isset($server[$_COOKIE['mongo_server']])) ? $server[$_COOKIE['mongo_server']] : $server[0];
    } else {
        return $server;
    }
}

/**
 * Render a document preview for the black code box with referenced
 * linked to the collection and id for that database reference.
 *
 * @param string $document
 * @return string $preview
 */
function renderDocumentPreview($mongo, $document)
{
    $document = prepareMongoDBDocumentForEdit($document);
    $preview = linkDocumentReferences($mongo, $document);
    $preview = print_r($preview, true);
    return $preview;
}

/**
 * Change any references to other documents to include a html link
 * to that document and collection. Used by the renderDocumentPreview() function.
 *
 * @param array $document
 * @return array $document
 */
function linkDocumentReferences($mongo, $document)
{
    foreach ($document as $key => $value) {
        if (is_array($value)) {
            if (isset($value['$ref'])) {
                $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($value['$ref']);
                $id = $value['$id'];

                $ref = findMongoDbDocument($mongo, $value['$id'], $_REQUEST['db'], $value['$ref']);
                if (!$ref) {
                    $ref = findMongoDbDocument($mongo, $value['$id'], $_REQUEST['db'], $value['$ref'], true);
                }

                $refDb = isset($value['$db']) ? $value['$db'] : $_REQUEST['db'];

                $document[$key]['$ref'] = '<a href="' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($refDb) . '&collection=' . $value['$ref'] . '">' . $value['$ref'] . '</a>';

                if ($ref['_id'] instanceof MongoId) {
                    $document[$key]['$id'] = '<a href="' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($refDb) . '&collection=' . $value['$ref'] . '&id=' . $value['$id'] . '">' . $value['$id'] . '</a>';
                } else {
                    $document[$key]['$id'] = '<a href="' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($refDb) . '&collection=' . $value['$ref'] . '&id=' . $value['$id'] . '&custom_id=1">' . $value['$id'] . '</a>';
                }

                if (isset($value['$db'])) {
                    $document[$key]['$db'] = '<a href="' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($refDb) . '">' . $refDb . '</a>';
                }
            } else {
                $document[$key] = linkDocumentReferences($mongo, $value);
            }
        }
    }
    return $document;
}

/**
 * Prepare user submitted array of PHP code as a MongoDB
 * document that can be saved.
 *
 * @param mixed $value
 * @return array $document
 */
function prepareValueForMongoDB($value)
{
    $customId = isset($_REQUEST['custom_id']);

    if (is_string($value)) {
        $value = preg_replace('/\'_id\' => \s*MongoId::__set_state\(array\(\s*\)\)/', '\'_id\' => new MongoId("' . (isset($_REQUEST['id']) ? $_REQUEST['id'] : '') . '")', $value);
        $value = preg_replace('/MongoId::__set_state\(array\(\s*\)\)/', 'new MongoId()', $value);
        $value = preg_replace('/MongoDate::__set_state\(array\(\s*\'sec\' => (\d+),\s*\'usec\' => \d+,\s*\)\)/m', 'new MongoDate($1)', $value);
        $value = preg_replace('/MongoBinData::__set_state\(array\(\s*\'bin\' => \'(.*?)\',\s*\'type\' => ([1,2,3,5,128]),\s*\)\)/m', 'new MongoBinData(\'$1\', $2)', $value);

        eval('$value = ' . $value . ';');

        if (!$value) {
            header('location: ' . $_SERVER['HTTP_REFERER'] . ($customId ? '&custom_id=1' : null));
            exit;
        }
    }

    $prepared = array();
    foreach ($value as $k => $v) {
        if ($k === '_id' && !$customId) {
            $v = new MongoId($v);
        }

        if ($k === '$id' && !$customId) {
            $v = new MongoId($v);
        }

        if (is_array($v)) {
            $prepared[$k] = prepareValueForMongoDB($v);
        } else {
            $prepared[$k] = $v;
        }
    }
    return $prepared;
}

/**
 * Prepare a MongoDB document for the textarea so it can be edited.
 *
 * @param array $value
 * @return array $prepared
 */
function prepareMongoDBDocumentForEdit($value)
{
    $prepared = array();
    foreach ($value as $key => $value) {
        if ($key === '_id') {
            $value = (string) $value;
        }
        if ($key === '$id') {
            $value = (string) $value;
        }
        if (is_array($value)) {
            $prepared[$key] = prepareMongoDBDocumentForEdit($value);
        } else {
            $prepared[$key] = $value;
        }
    }
    return $prepared;
}

/**
 * Search for a MongoDB document based on the id
 *
 * @param string $id The ID to search for
 * @param string $db The db to use
 * @param string $collection The collection to search in
 * @param bool $forceCustomId True to force a custom id search
 * @return mixed $document
 *
 */
function findMongoDbDocument($mongo, $id, $db, $collection, $forceCustomId = false)
{
    $collection = $mongo->selectDB($db)->selectCollection($collection);

    if (isset($_REQUEST['custom_id']) || $forceCustomId) {
        if (is_numeric($id)) {
            $id = (int) $id;
        }
        $document = $collection->findOne(array('_id' => $id));
    } else {
        $document = $collection->findOne(array('_id' => new MongoId($id)));
    }

    return $document;
}

// Actions
try {
    // SEARCH BY ID
    if (isset($_REQUEST['search']) && !is_object(json_decode($_REQUEST['search']))) {
        $customId = false;
        $document = findMongoDbDocument($mongo, $_REQUEST['search'], $_REQUEST['db'], $_REQUEST['collection']);

        if (!$document) {
            $document = findMongoDbDocument($mongo, $_REQUEST['search'], $_REQUEST['db'], $_REQUEST['collection'], true);
            $customId = true;
        }

        if (isset($document['_id'])) {
            $url = $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];

            if ($customId) {
                header('location: ' . $url . '&custom_id=true');
            } else {
                header('location: ' . $url);
            }
        }
    }

    // DELETE DB
    if (isset($_REQUEST['delete_db']) && $readOnly !== true) {
        $mongo
            ->selectDB($_REQUEST['delete_db'])
            ->drop();

        header('location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // CREATE DB
    if (isset($_REQUEST['create_db']) && $readOnly !== true) {
        $mongo->selectDB($_REQUEST['create_db'])->createCollection('__tmp_collection_');
        $mongo->selectDB($_REQUEST['create_db'])->dropCollection('__tmp_collection_');

        header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['create_db']));
        exit;

    }

    // CREATE DB COLLECTION
    if (isset($_REQUEST['create_collection']) && $readOnly !== true) {
        $mongo
            ->selectDB($_REQUEST['db'])
            ->createCollection($_REQUEST['create_collection']);

        header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['create_collection']);
        exit;
    }

    // DELETE DB COLLECTION
    if (isset($_REQUEST['delete_collection']) && $readOnly !== true) {
        $mongo
            ->selectDB($_REQUEST['db'])
            ->selectCollection($_REQUEST['delete_collection'])
            ->drop();

        header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']));
        exit;
    }

    // DELETE DB COLLECTION DOCUMENT
    if (isset($_REQUEST['delete_document']) && $readOnly !== true) {
        $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($_REQUEST['collection']);

        if (isset($_REQUEST['custom_id'])) {
            $id = $_REQUEST['delete_document'];
            if (is_numeric($id)) {
                $id = (int) $id;
            }
            $collection->remove(array('_id' => $id));
        } else {
            $collection->remove(array('_id' => new MongoId($_REQUEST['delete_document'])));
        }

        header('location: ' . $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection']);
        exit;
    }

    // DELETE DB COLLECTION DOCUMENT FIELD AND VALUE
    if (isset($_REQUEST['delete_document_field']) && $readOnly !== true) {
        $coll = $mongo
            ->selectDB($_REQUEST['db'])
            ->selectCollection($_REQUEST['collection']);

        $document = findMongoDbDocument($mongo, $_REQUEST['id'], $_REQUEST['db'], $_REQUEST['collection']);
        unset($document[$_REQUEST['delete_document_field']]);
        $coll->save($document);

        $url = $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];
        header('location: ' . $url);
        exit;
    }

    // INSERT OR UPDATE A DB COLLECTION DOCUMENT
    if (isset($_POST['save']) && $readOnly !== true) {
        $customId = isset($_REQUEST['custom_id']);
        $collection = $mongo->selectDB($_REQUEST['db'])->selectCollection($_REQUEST['collection']);

        $document = prepareValueForMongoDB($_REQUEST['value']);
        $collection->save($document);

        $url = $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] . '&id=' . (string) $document['_id'];
        header('location: ' . $url . ($customId ? '&custom_id=1' : null));
        exit;
    }

// Catch any errors and redirect to referrer with error
} catch (Exception $e) {
    header('location: ' . $_SERVER['HTTP_REFERER'] . '&error=' . htmlspecialchars($e->getMessage()));
    exit;
}
?>
<html>
  <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>PHP MongoDB Admin</title>
    <link rel="shortcut icon" href="data:image/x-icon;base64,AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAIAAAAAAAAAQAAAAAAAAAAAAAAAAA
AAAAAAA9Umn/K0Jb/y9FXv8vRV7/L0Ve/y9FXv8vRV7/OU9m/0JWbf8vRV7/L0Ve/y9FXv8vRV7/
L0Ve/y9FXv8vRV7/LkRe/x83Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/zhOZv9GWnD/HzdS/x83Uv8f
N1L/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS/x83Uv8fN1L/HzdS/x83Uv9SbnD/Vm92/x83
Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/x83Uv8uRF7/HzdS/x83Uv8fN1L/HzdS/x83Uv9MaWX/UKgz
/0SOJf9NZmn/HzdS/x83Uv8fN1L/HzdS/x83Uv8fN1L/LkRe/x83Uv8fN1L/HzdS/x83Uv86UWP/
Sqcc/1SsOP9HkiT/QpAe/zNKX/8fN1L/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS/x83Uv8i
OVT/VZBF/06pJf9XrkH/SZUo/0OSH/9SfVT/LURa/x83Uv8fN1L/HzdS/x83Uv8uRF7/HzdS/x83
Uv8fN1L/Ql5g/0qmGf9TrDL/WrFJ/0mWKv9IlSf/SZIt/0NjWv8fN1L/HzdS/x83Uv8fN1L/LkRe
/x83Uv8fN1L/HzdS/0J0Rf9NqCD/Va05/16yT/9LmCv/SZYo/0mWKf89cED/HzdS/x83Uv8fN1L/
HzdS/y5EXv8fN1L/HzdS/x83Uv9AeT3/UKko/1evQf9htFX/TZks/0qYKv9KmCn/OnI7/x83Uv8f
N1L/HzdS/x83Uv8uRF7/HzdS/x83Uv8fN1L/RG1V/0+rLf9asEj/ZbZa/02aLv9MmSz/TJgu/0Rr
VP8fN1L/HzdS/x83Uv8fN1L/LkRe/x83Uv8fN1L/HzdS/zhTXv9Vqzr/XbJP/2i4X/9OnDD/S5kr
/1GKR/83UF7/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS/x83Uv8fN1L/WIxc/16zUf9sumT/
T50x/0aZJf9GY2L/HzdS/x83Uv8fN1L/HzdS/x83Uv8uRF7/HzdS/x83Uv8fN1L/HzdS/zZOYf9f
tFT/cLxp/02dLf9WkUr/HzdS/x83Uv8fN1L/HzdS/x83Uv8fN1L/LkRe/x83Uv8fN1L/HzdS/x83
Uv8fN1L/XXx2/2y8Zf9Qmjj/NEpg/x83Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/y5EXv8fN1L/HzdS
/x83Uv8fN1L/HzdS/x83Uv9cgnD/Q1tn/x83Uv8fN1L/HzdS/x83Uv8fN1L/HzdS/x83Uv89Umn/
K0Jb/y9FXv8vRV7/L0Ve/y9FXv8vRV7/OlBl/zRJYf8vRV7/L0Ve/y9FXv8vRV7/L0Ve/y9FXv8v
RV7/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA
AAAAAAAAAAAAAA==" type="image/x-icon" />
    <style>
    @charset "UTF-8";
    /* CSS Document */

        html{color:#000;background:#FFF;}
    	body,div,dl,dt,dd,ul,ol,li,h1,h2,h3,h4,h5,h6,pre,code,form,fieldset,legend,input,textarea,p,blockquote,th,td{margin:0;padding:0;color:#666;}
    	table{border-collapse:collapse;border-spacing:0;}
    	fieldset,img{border:0;}
    	address,caption,cite,code,dfn,em,strong,th,var{font-style:normal;font-weight:normal;}
    	li{list-style:none;}
    	caption,th{text-align:left;}
    	h1,h2,h3,h4,h5,h6{font-size:100%;font-weight:normal;}
    	q:before,q:after{content:'';}
    	abbr,acronym{border:0;font-variant:normal;}
    	sup{vertical-align:text-top;}
    	sub{vertical-align:text-bottom;}
    	input,textarea,select{font-family:inherit;font-size:inherit;font-weight:inherit;}
    	input,textarea,select{*font-size:100%;}
    	legend{color:#000;}
        html { background: #666; font:13px/1.231 "Lucida Grande",verdana,arial,helvetica,clean,sans-serif;*font-size:small;*font:x-small;}
    	table {font-size:inherit;font:100%;}
    	pre,code,kbd,samp,tt{font-family:monospace;*font-size:108%;line-height:100%;}
        a:link, a:visited, a:active { text-decoration:none; color:#666; outline:none; border:0; }
        a:hover { color:#F90; text-decoration:none; border:0; }

        pre {
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          padding: 10px;
          background-color: #222;
          overflow/**/: auto;
          margin-bottom: 15px;
          line-height: 17px;
          font-size: 13px;
          color: #fff;
          font-family: "Bitstream Vera Sans Mono", monospace;
          white-space: pre-wrap;
        }

        pre a {
          color: #fff !important;
          text-decoration: underline !important;
        }

        #content {
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          margin-top: 20px;
          margin-bottom: 20px;
          padding: 20px;
          width: 90%;
          margin-left: auto;
          margin-right: auto;
          position:relative;
          background:#FFF;
          color: #495a7e;
        }
        #content h1 { font-size: 20px; font-weight: bold; margin-bottom: 15px; color:#FBDDA4; }
        #content h2 { font-size: 14px; font-weight: bold; margin-bottom: 15px; margin-top: 10px; color:#5AAC41; }

        #footer {
          margin-top: 15px;
          text-align: center;
          font-weight: bold;
          font-size: 10px;
    	  color:#FFF;
        }

        #create_form {
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          padding: 15px;
          background: #5AAC41;
          width: 400px;
          float: right;
          margin-bottom: 10px;
        }
        #create_form label {
          float: left;
          padding: 4px;
          font-weight: bold;
          margin-right: 10px;
    	  color:#FFF;
        }
        #pager {
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          background: #f5f5f5;
          padding: 8px;
          margin-bottom: 15px;
          width: 350px;
          float: left;
        }
        #search {
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          background: #F90;
          padding: 8px;
          margin-bottom: 15px;
          width: 500px;
          float: right;
        }
    	#mongoLogo{
    		background-image:url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAALAAAABLCAYAAADQ4g2EAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAA+tpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuMC1jMDYxIDY0LjE0MDk0OSwgMjAxMC8xMi8wNy0xMDo1NzowMSAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wTU09Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9tbS8iIHhtbG5zOnN0UmVmPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvc1R5cGUvUmVzb3VyY2VSZWYjIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtbG5zOmRjPSJodHRwOi8vcHVybC5vcmcvZGMvZWxlbWVudHMvMS4xLyIgeG1wTU06T3JpZ2luYWxEb2N1bWVudElEPSJ1dWlkOjVEMjA4OTI0OTNCRkRCMTE5MTRBODU5MEQzMTUwOEM4IiB4bXBNTTpEb2N1bWVudElEPSJ4bXAuZGlkOjYxNjQzNzdFN0RENTExRTI4Nzc4RDBBOThDRUQ4NUJFIiB4bXBNTTpJbnN0YW5jZUlEPSJ4bXAuaWlkOjYxNjQzNzdEN0RENTExRTI4Nzc4RDBBOThDRUQ4NUJFIiB4bXA6Q3JlYXRvclRvb2w9IkFkb2JlIElsbHVzdHJhdG9yIENTNS4xIj4gPHhtcE1NOkRlcml2ZWRGcm9tIHN0UmVmOmluc3RhbmNlSUQ9InV1aWQ6YmVmODllNmEtZDA2Mi1iZDQ2LWIxODUtZmZhNzk4YjhiNGEwIiBzdFJlZjpkb2N1bWVudElEPSJ4bXAuZGlkOjAzODAxMTc0MDcyMDY4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');
    		background-repeat:no-repeat;
    		width:200px;
    		height:75px;
    		float:left;
    		margin-left:-32px;
    	}
        table {
          background: #000;
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          border-collapse: collapse;
          width: 100%;
        }
        table th {
          color: #fff;
          font-weight: bold;
          padding: 8px;
        }
        table td {
          padding: 8px;
        }
        table td a {
          font-weight: bold;
        }
        table tbody tr {
          background-color: #F3F4EB;
          border-bottom: 1px solid #ccc;
        }
        table tbody tr:hover {
        	background-color: #333;
        }
        .save_button {
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          background-color: #F90;
          color: #fff;
          padding: 4px;
          font-weight: bold;
          padding-left: 10px;
          padding-right: 10px;
        }
        .save_button:hover {
          background-color: #ccc;
          color: #333;
          cursor: pointer;
        }
        textarea {
          padding: 10px;
          -moz-border-radius: 10px;
          -webkit-border-radius: 10px;
          border-radius: 10px;
          border: 1px solid #ccc;
          width: 100%;
          height: 350px;
          margin-top: 10px;
          margin-bottom: 10px;
        }
    	.footer{color:#CCC;}
    </style>

  </head>

  <body>
  <div id="content">
    <h1>
      <a href="http://docs.mongodb.org"><div id="mongoLogo"></div></a>
      <?php if (is_array($server)): ?>
        <?php if (count($server) > 1): ?>
          <select id="server" onChange="document.cookie='mongo_server='+this[this.selectedIndex].value;document.location.reload();return false;">
            <?php foreach ($server as $key => $s): ?>
              <option value="<?php echo $key ?>"<?php if (isset($_COOKIE['mongo_server']) && $_COOKIE['mongo_server'] == $key): ?> selected="selected"<?php endif;?>><?php echo preg_replace('/\/\/(.*):(.*)@/', '//$1:*****@', $s); ?></option>
            <?php endforeach;?>
          </select>
        <?php else: ?>
          <?php echo $server[0] ?>
        <?php endif;?>
      <?php else: ?>
        <?php echo $server ?>
      <?php endif;?>
    </h1>
    <?php if (isset($_REQUEST['error'])): ?>
      <div class="error">
        <?php echo $_REQUEST['error'] ?>
      </div>
    <?php endif;?>

<?php // START ACTION TEMPLATES ?>

<?php // CREATE AND LIST DBs TEMPLATE ?>
<?php if (!isset($_REQUEST['db'])): ?>

  <?php if ($readOnly !== true): ?>
    <div id="create_form">
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="POST">
        <label for="create_db_field">Create Database</label>
        <input type="text" name="create_db" id="create_db_field" />
        <input type="submit" name="save" value="Save" />
      </form>
    </div>
  <?php endif;?>

  <h2>Databases</h2>

  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Collections</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php $dbs = $mongo->listDBs()?>
      <?php foreach ($dbs['databases'] as $db): if ($db['name'] === 'local' || $db['name'] === 'admin') {
        continue;
    }
    ?>
																	        <tr>
																	          <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($db['name']) ?>"><?php echo $db['name'] ?></a></td>
																	          <td><?php echo count($mongo->selectDb($db['name'])->listCollections()) ?></td>

																	          <?php if ($readOnly !== true): ?>
																	            <td><a href="<?php echo $_SERVER['PHP_SELF'] ?>?delete_db=<?php echo urlencode($db['name']) ?>" onClick="return confirm('Are you sure you want to delete this database?');">Delete</a></td>
																	          <?php else: ?>
            <td>&nbsp;</td>
          <?php endif;?>
        </tr>
      <?php endforeach;?>
    </tbody>
  </table>

<?php // CREATE AND LIST DB COLLECTIONS ?>
<?php elseif (isset($_REQUEST['db']) && !isset($_REQUEST['collection'])): ?>

  <?php if ($readOnly !== true): ?>
    <div id="create_form">
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>" method="POST">
        <label for="create_collection_field">Create Collection</label>
        <input type="text" name="create_collection" id="create_collection_field" />
        <input type="submit" name="create" value="Save" />
      </form>
    </div>
  <?php endif;?>

  <h2>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>">Databases</a> >>
    <?php echo $_REQUEST['db'] ?>
  </h2>
  <table>
    <thead>
      <tr>
        <th>Collection Name</th>
        <th>Documents</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php $collections = $mongo->selectDB($_REQUEST['db'])->listCollections()?>
      <?php foreach ($collections as $collection): ?>
        <tr>
          <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $collection->getName() ?>"><?php echo $collection->getName() ?></a></td>
          <td><?php echo $collection->count(); ?></td>

         <?php if ($readOnly !== true): ?>
            <td><a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>&delete_collection=<?php echo $collection->getName() ?>" onClick="return confirm('Are you sure you want to delete this collection?');">Delete</a></td>
          <?php else: ?>
            <td>&nbsp;</td>
          <?php endif;?>
        </tr>
      <?php endforeach;?>
    </tbody>
  </table>

<?php // CREATE AND LIST DB COLLECTION DOCUMENTS ?>
<?php elseif (!isset($_REQUEST['id']) || isset($_REQUEST['search'])): ?>

    <?php
$max = 20;
$page = isset($_REQUEST['page']) ? $_REQUEST['page'] : 1;
$limit = $max;
$skip = ($page - 1) * $max;

if (isset($_REQUEST['search']) && is_object(json_decode($_REQUEST['search']))) {
    $search = json_decode($_REQUEST['search'], true);

    $cursor = $mongo
        ->selectDB($_REQUEST['db'])
        ->selectCollection($_REQUEST['collection'])
        ->find($search)
        ->limit($limit)
        ->skip($skip);
} else {
    $cursor = $mongo
        ->selectDB($_REQUEST['db'])
        ->selectCollection($_REQUEST['collection'])
        ->find()
        ->limit($limit)
        ->skip($skip)
        ->sort(array('_id' => 1));
}

$total = $cursor->count();
$pages = ceil($total / $max);

if ($pages && $page > $pages) {
    header('location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}
?>

    <h2>
      <a href="<?php echo $_SERVER['PHP_SELF'] ?>">Databases</a> >>
      <a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>"><?php echo $_REQUEST['db'] ?></a> >>
      <?php echo $_REQUEST['collection'] ?> (<?php echo $cursor->count() ?> Documents)
    </h2>

    <?php if ($pages > 1): ?>
      <div id="pager">
        <?php echo $pages ?> pages. Go to page
        <input type="text" name="page" size="4" value="<?php echo $page ?>" onChange="javascript: location.href = '<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?><?php if (isset($_REQUEST['search'])): ?>&search=<?php echo urlencode($_REQUEST['search']) ?><?php endif;?>&page=' + this.value;" />
        <input type="button" name="go" value="Go" />
      </div>
    <?php endif;?>

    <div id="search">
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="GET">
        <input type="hidden" name="db" value="<?php echo $_REQUEST['db'] ?>" />
        <input type="hidden" name="collection" value="<?php echo $_REQUEST['collection'] ?>" />
        <label for="search_input">Search</label>
        <input type="text" id="search_input" name="search" size="36"<?php echo isset($_REQUEST['search']) ? ' value="' . htmlspecialchars($_REQUEST['search']) . '"' : '' ?> />
        <input type="submit" name="submit_search" value="Search" />
      </form>
    </div>

    <table>
      <thead>
        <th colspan="3">ID</th>
      </thead>
      <tbody>
        <?php foreach ($cursor as $document): ?>
          <tr>
            <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId): ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&id=<?php echo (string) $document['_id'] ?>"><?php echo (string) $document['_id'] ?></a></td>
            <?php else: ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&id=<?php echo (string) $document['_id'] ?>&custom_id=1"><?php echo (string) $document['_id'] ?></a></td>
            <?php endif;?>
            <td>
              <?php
if (isset($search)) {
    $displayValues = array();

    $searchKeys = isset($search['$query']) ? $search['$query'] : $search;

    foreach ($searchKeys as $fieldName => $searchQuery) {
        if ($fieldName != '_id' && $fieldName[0] != '$' && isset($document[$fieldName])) {
            $fieldValue = $document[$fieldName];

            if (!is_array($fieldValue) && !is_object($fieldValue)) {
                $displayValues[] = $fieldName . ': ' . substr(str_replace("\n", '', htmlspecialchars($fieldValue)), 0, 100);
            }
        }
    }

    echo implode(' - ', $displayValues);
}

if (!isset($displayValues) || !count($displayValues)) {
    foreach ($document as $fieldName => $fieldValue) {
        if ($fieldName != '_id' && !is_array($fieldValue) && !is_object($fieldValue)) {
            echo $fieldName . ': ' . substr(str_replace("\n", '', htmlspecialchars($fieldValue)), 0, 100);
            break;
        }
    }
}
?>
            </td>
            <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId && $readOnly !== true): ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a></td>
            <?php elseif ($readOnly !== true): ?>
              <td><a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>&custom_id=1" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a></td>
            <?php endif;?>
          </tr>
        <?php endforeach;?>
      </tbody>
    </table>

    <?php if ($readOnly !== true): ?>
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="POST">
        <?php if (isset($document)): ?>
          <input type="hidden" name="values[_id]" value="<?php echo $document['_id'] ?>" />

          <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId): ?>
            <input type="hidden" name="custom_id" value="1" />
          <?php endif;?>
        <?php endif;?>

        <?php foreach ($_REQUEST as $k => $v): ?>
          <input type="hidden" name="<?php echo $k ?>" value="<?php echo $v ?>" />
        <?php endforeach;?>

        <h2>Create New Document</h2>
        <textarea name="value"></textarea>
        <input type="submit" name="save" value="Save" />
      </form>
    <?php endif;?>

<?php // EDIT DB COLLECTION DOCUMENT ?>
<?php else: ?>

<h2>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>">Databases</a> >>
    <a href="<?php echo $_SERVER['PHP_SELF'] ?>?db=<?php echo urlencode($_REQUEST['db']) ?>"><?php echo $_REQUEST['db'] ?></a> >>
    <a href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>"><?php echo $_REQUEST['collection'] ?></a> >>
    <?php echo $_REQUEST['id'] ?>
    </h2>
    <?php $document = findMongoDbDocument($mongo, $_REQUEST['id'], $_REQUEST['db'], $_REQUEST['collection']);?>

    <pre><code><?php echo renderDocumentPreview($mongo, $document) ?></code></pre>

    <?php $prepared = prepareMongoDBDocumentForEdit($document)?>

    <?php if ($readOnly !== true): ?>
      <form action="<?php echo $_SERVER['PHP_SELF'] ?>" method="POST">
        <input type="hidden" name="values[_id]" value="<?php echo $document['_id'] ?>" />

        <?php foreach ($_REQUEST as $k => $v): ?>
          <input type="hidden" name="<?php echo $k ?>" value="<?php echo $v ?>" />
        <?php endforeach;?>

        <h2>Edit Document</h2>
        <textarea name="value"><?php echo var_export($prepared, true) ?></textarea>
        <input type="submit" name="save" value="Save" />
      </form>
    <?php endif;?>
    <br/>
    <?php if (is_object($document['_id']) && $document['_id'] instanceof MongoId && $readOnly !== true): ?>
      <a class="save_button" href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a>
    <?php elseif ($readOnly !== true): ?>
      <a class="save_button" href="<?php echo $_SERVER['PHP_SELF'] . '?db=' . urlencode($_REQUEST['db']) . '&collection=' . $_REQUEST['collection'] ?>&delete_document=<?php echo (string) $document['_id'] ?>&custom_id=1" onClick="return confirm('Are you sure you want to delete this document?');">Delete</a>
    <?php endif;?>

    <?php endif;?>
<?php // END ACTION TEMPLATES ?>

      <p id="footer"><span class="footer">Created by <a href="http://www.twitter.com/jwage" target="_BLANK">Jonathan H. Wage</a> | Theme by Ted Veatch</span></p>
    </div>
  </body>
</html>
