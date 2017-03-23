<?php
/**
 * Импорт проектов корпоративного развития из MSSQL
 * User: ilv.semenov
 * Date: 28.01.16
 * Time: 17:07
 */

class ImportIpmSprPKR extends CComponent {
	private static $_init = NULL;

	/*
	 * Агрегирующий метод
	 * */
	public function run() {
		# Массив количеств по каждому выполненному методу
		$count = array();

		# Импорт ПКР
		$count += $result = $this->importPKR();
		$this->showDebugInfo($result);

		return array_sum($count);
	}

	/*
	 * Инициализация объекта
	 * */
	public static function init() {
		if(self::$_init == NULL) {
			self::$_init = new self();
		}
		return self::$_init;
	}
	/*
	 * Импорт Проектов корпоративного развития (ПКР) из MS в MYSQL
	 * */
	public function importPKR () {
		$db = Yii::app()->db;
		# Колонки, которые приходят из MSSQL
		$requiredColumns = array(
			'instanceID',
			'typeInstanceID',
			'isDelete',
			'Name',
			'FullName',
			'ShortName',
			'isFolder',
			'GuidDV',
			'ItemComment',
			'ItemNotAvailable',
			'ItemOrder',
			'RegNum',
			'MainObjective',
			'FunctionalArea',
			'DepartmentsInvolved',
			'ReasonCancellationRenewal',
			'MainGoalReached',
			'ActionState',
			'sd_subcategoryid',
			'sd_subcategoryname',
			'ManagerLogin',
			'ManagerFullName',
			'ManagerPosition',
			'SupervisorLogin',
			'SupervisorFullName',
			'SupervisorPosition',
			'CustomerLogin',
			'CustomerFullName',
			'CustomerPosition',
			'ResponsibleLogin',
			'Responsible_LastName',
			'Responsible_FirstName',
			'Responsible_MiddleName',
			'ProjectCGResponsiblephPerson_IPM_ID',
			'OwnerId',
			'OwnerWeight',
		);
		# Колонки с датами вынесены отдельно, потому что требуют особой обработки
		$dateColumns = array(
			'dateInsert', 'dateUpdate', 'dateDelete', 'DateApproval', 'DatePlan', 'DateFact'
		);
		// Берем активные ПКР из МССКЛ
		$query = "
			SELECT
				".join(', ', $requiredColumns)."
				, CONVERT(VARCHAR(19), p.dateInsert, 120) dateInsert
				, CONVERT(VARCHAR(19), p.dateUpdate, 120) dateUpdate
				, CONVERT(VARCHAR(19), p.dateDelete, 120) dateDelete

				, CONVERT(VARCHAR(19), p.DateApproval, 120) DateApproval
				, CONVERT(VARCHAR(19), p.DatePlan, 120) DatePlan
				, CONVERT(VARCHAR(19), p.DateFact, 120) DateFact
			FROM [integration].[dbo].[i2view_SprProjectCG] p WHERE isDelete = 0
		";
		$pkrs = Yii::app()->msdb1->createCommand($query)->queryAll();
		if(!count($pkrs)) {
			return;
		}
		foreach($dateColumns as $column) {
			array_push($requiredColumns, $column);
		}

		# Шаблон будущего запроса
		$intoQuery = array(
			'insert' => 'INSERT INTO ipm_spr_pkr ('.join(', ', $requiredColumns).') VALUES',
			'values' => '',
			'update' => 'ON DUPLICATE KEY UPDATE'
		);

		# Идентификаторы текущих ПКР понадобятся позже чтобы очистить всё что NOT IN
		$pkrInstanceIds = array();
		foreach($pkrs as $pkr) {
			$pkrInstanceIds[] = $pkr['instanceID'];
		}
		# 1.1 Дописываем $intoQuery['values']
		$values = $this->_getValuesForQuery($pkrs);
		if(!count($values)) {
			return;
		}
		$intoQuery['values'] = join(', ', $values);

		# 1.2 Дописываем $intoQuery['update']
		$update = array();
		foreach($requiredColumns as $column) {
			$update[] = " $column = VALUES($column)";
		}
		$intoQuery['update'] .= join(',', $update);

		# Запуск Транзакции
		$transaction = $db->beginTransaction();
		$affectedRows = 0;
		try {
			$affectedRows = $db->createCommand(join(' ', $intoQuery))->execute();
			# Чистим все старые ПКР которые могли образоваться (NOT IN)
			if(count($pkrInstanceIds)) {
				$deleteOldPkrQuery = "UPDATE ipm_spr_pkr SET active='N' WHERE instanceID NOT IN ('".join("', '", $pkrInstanceIds)."')";
				$db->createCommand($deleteOldPkrQuery)->execute();
			}
			$transaction->commit();
		} catch (CDbException $e) {
			echo $e->getMessage();
			$transaction->rollback();
		}

		return array(
			__FUNCTION__ => count($pkrInstanceIds)
		);
	}

	/*
	 * Методы private
	 * */
	# Метод для наполнения $values для INSERT запроса
	private function _getValuesForQuery($array) {
		$values = array();
		foreach($array as $line) {
			$row = array();
			foreach($line as $value) {
				$valueOfColumn = $value;
				# Если строка(Т.е. содержит данные), оборачиваем в кавычки
				if(is_string($value)) {
					$valueOfColumn = "'".$valueOfColumn."'";
				} elseif($value == NULL) {
					# Обрабатываем NULL правильно
					$valueOfColumn = 'NULL';
				}
				$row[] = $valueOfColumn;
			}
			$values[] = "(".join(", ", $row).")";
		}
		return $values;
	}

	/*
	 * Отображение сводной информации в консоли (Только для Debug режима)
	 * */
	private function showDebugInfo($array) {
		if(YII_DEBUG)
			echo 'Метод '.key($array).'() выполнен: Возвращено '.$array[key($array)]." строк \n";
	}
}
