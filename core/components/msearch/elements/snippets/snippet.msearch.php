<?php
if (!empty($indexer)) {
	return require $modx->getOption('core_path').'components/msearch/elements/snippets/indexer.php';
}

$where = $modx->fromJSON($where);
if (is_array($where)) {
	$tmp = $modx->newQuery('modResource', $where);
	$tmp->select('id');
	$tmp->prepare();
	$tmp = $tmp->toSQL();
	$where = 'AND' . substr($tmp, strpos($tmp, 'WHERE') + 5);
}
$context = !empty($scriptProperties['context']) ? $scriptProperties['context'] : $modx->resource->context_key;

if (!empty($_GET[$parentsVar])) {
	$parents = $_GET[$parentsVar];
	$modx->setPlaceholder($plPrefix.'parents', $parents);
}

$add_query = '';
if (empty($showHidden)) {$add_query .= ' AND `hidemenu` != 1';}
if (empty($showUnpublished)) {$add_query .= ' AND `published` != 0';}
if (!empty($templates)) {$add_query .= " AND `template` IN ($templates)";}
if (!empty($resources)) {$add_query .= " AND `rid` IN ($resources)";}
if (!empty($parents)) {
	$tmp = explode(',',$parents);
	$arr = $tmp;
	
	foreach ($tmp as $v) {
		$arr = array_merge($arr, $modx->getChildIds($v, 10, array('context' => $context)));
	}
	$ids = implode(',', $arr);
	$add_query .= " AND `rid` IN ($ids)";
}

// Подключаем класс mSearch
if (!isset($modx->mSearch) || !is_object($modx->mSearch)) {
	$modx->mSearch = $modx->getService('msearch','mSearch',$modx->getOption('msearch.core_path',null,$modx->getOption('core_path').'components/msearch/').'model/msearch/',$scriptProperties);
	if (!($modx->mSearch instanceof mSearch)) return '';
}
$modx->mSearch->get_execution_time();

// Обрабатываем поисковый запрос
if (isset($_GET[$queryVar])) {
	$query = trim(strip_tags($_GET[$queryVar]));
}
else {$query = 0;}

if (empty($query) && isset($_GET[$queryVar])) {
	$modx->setPlaceholder($plPrefix.'error', $modx->lexicon('mse.err_no_query'));
	return;
}
else if (strlen($query) < $minQuery && !empty($query)) {
	$modx->setPlaceholder($plPrefix.'error', $modx->lexicon('mse.err_min_query'));
	return;
}
else if (empty($query)) {
	$modx->setPlaceholder($plPrefix.'error', ' ');
	return;
}
else {
	$modx->setPlaceholder($plPrefix.'query', $query);
}


// Получаем все возможные формы слов запроса
$query_string = $modx->mSearch->getAllForms($query);

// Составляем запросы в БД
$db_index = $modx->getTableName('ModResIndex');
$db_res = $modx->getTableName('modResource');
// Определяем количество результатов
$sql = "SELECT COUNT(`rid`) as `id` FROM $db_index 
	LEFT JOIN $db_res `modResource` ON $db_index.`rid` = `modResource`.`id`
	WHERE (MATCH (`resource`,`index`) AGAINST ('$query_string') OR `resource` LIKE '%$query%')
	AND (`modResource`.`searchable` = 1 $add_query) $where";

$q = new xPDOCriteria($modx, $sql);
if ($q->prepare() && $q->stmt->execute()){
	$total = $q->stmt->fetchColumn();
	$modx->setPlaceholder($totalVar, $total);
	if ($total == 0) {
		$modx->setPlaceholder($plPrefix.'error', $modx->lexicon('mse.err_no_results'));
		$modx->setPlaceholder($plPrefix.'query_string',$sql);
		return;
	}
}
// Если их больше 0 - запускаем основной поиск
$sql = "SELECT `rid`,`resource`,
	MATCH (`resource`,`index`) AGAINST ('>\"$query\" <($query_string)' IN BOOLEAN MODE) as `rel`
	FROM $db_index 
	LEFT JOIN $db_res `modResource` ON $db_index.`rid` = `modResource`.`id`
	WHERE (MATCH (`resource`,`index`) AGAINST ('>\"$query\" <($query_string)' IN BOOLEAN MODE) OR `resource` LIKE '%$query%')
	AND (`modResource`.`searchable` = 1 $add_query) $where
	ORDER BY `rel` DESC";
if (!empty($limit)) {$sql .= " LIMIT $offset,$limit";}
$modx->setPlaceholder($plPrefix.'query_string',$sql);
$q = new xPDOCriteria($modx, $sql);
$q->prepare();
$q->stmt->execute();

$res = $q->stmt->fetchAll(PDO::FETCH_ASSOC);
$modx->setPlaceholder($plPrefix.'query_time',$modx->mSearch->get_execution_time());
$result = array();
$i = $offset;

if ($includeMS != 0) {
	// Подключение класса miniShop
	if (!isset($modx->miniShop) || !is_object($modx->miniShop)) {
	  $modx->miniShop = $modx->getService('minishop','miniShop', $modx->getOption('core_path').'components/minishop/model/minishop/', array());
	  if (!($modx->miniShop instanceof miniShop)) return '';
	}
}

// Возвращаем либо список подходящих ID, либо готовый результат
if ($returnIds == 1) {
	$ids = array();
	foreach ($res as $v) {
		$ids[] = $v['rid'];
	}
	return implode(',', $ids);
}
else {
	foreach ($res as $v) {
		if (is_array($where) && !empty($where)) {
			$q = array_merge(array('id' => $v['rid']), $where);
		}
		else {
			$q = $v['rid'];
		}
		if ($tmp = $modx->getObject('modResource', $q)) {
			$arr = $tmp->toArray();
			$i++;
			$arr['num'] = $i;
			$arr['intro'] = $modx->mSearch->Highlight($v['resource'], $query);
			if ($includeTVs && !empty($includeTVList)) {
				foreach ($includeTVList as $k => $v) {
					$arr[$tvPrefix.$v] = $tmp->getTVValue($v);
				}
			}
			if ($includeMS != 0 && $tmp2 = $modx->getObject('ModGoods', array('gid' => $v['rid'], 'wid' => $_SESSION['minishop']['warehouse']))) {
				$tmp2 = $tmp2->toArray();
				unset($tmp2['id']);
				foreach ($tmp2 as $k => $v) {
					$arr[$plPrefix.$k] = $v;
				}
			}
			$result[] = $modx->getChunk($tpl, $arr);
		}
	}
	$modx->setPlaceholder($plPrefix.'render_time',$modx->mSearch->get_execution_time());

	if ($i == 0) {
		$modx->setPlaceholder($plPrefix.'error', $modx->lexicon('mse.err_no_results'));
		return;
	}
	return implode($outputSeparator, $result);
}
