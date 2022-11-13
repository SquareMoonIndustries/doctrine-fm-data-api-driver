<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace MSDev\DoctrineFMDataAPIDriver;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

class FMPlatform extends AbstractPlatform
{

    public function getBinaryMaxLength(): int
    {
        return 32704;
    }

    public function getBinaryDefaultLength(): int
    {
        return 1;
    }

    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BLOB(1M)';
    }

    public function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = array(
            'smallint'      => 'smallint',
            'bigint'        => 'bigint',
            'integer'       => 'integer',
            'time'          => 'time',
            'date'          => 'date',
            'varchar'       => 'string',
            'character'     => 'string',
            'varbinary'     => 'binary',
            'binary'        => 'binary',
            'clob'          => 'text',
            'blob'          => 'blob',
            'decimal'       => 'decimal',
            'double'        => 'float',
            'real'          => 'float',
            'timestamp'     => 'datetime',
        );
    }

    public function isCommentedDoctrineType(Type $doctrineType): bool
    {
        if ($doctrineType->getName() === Types::BOOLEAN) {
            // We require a commented boolean type in order to distinguish between boolean and smallint
            // as both (have to) map to the same native type.
            return true;
        }

        return parent::isCommentedDoctrineType($doctrineType);
    }

    protected function getVarcharTypeDeclarationSQLSnippet($length, $fixed): string
    {
        return $fixed ? ($length ? 'CHAR(' . $length . ')' : 'CHAR(255)')
                : ($length ? 'VARCHAR(' . $length . ')' : 'VARCHAR(255)');
    }

    protected function getBinaryTypeDeclarationSQLSnippet($length, $fixed): string
    {
        return $fixed ? 'BINARY(' . ($length ?: 255) . ')' : 'VARBINARY(' . ($length ?: 255) . ')';
    }

    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'CLOB(1M)';
    }

    public function getName(): string
    {
        return 'fms';
    }

    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'SMALLINT';
    }

    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'INTEGER' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return 'BIGINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return 'SMALLINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        $autoinc = '';
        if ( ! empty($columnDef['autoincrement'])) {
            $autoinc = ' GENERATED BY DEFAULT AS IDENTITY';
        }

        return $autoinc;
    }

    public function getBitAndComparisonExpression($value1, $value2): string
    {
        return 'BITAND(' . $value1 . ', ' . $value2 . ')';
    }

    public function getBitOrComparisonExpression($value1, $value2): string
    {
        return 'BITOR(' . $value1 . ', ' . $value2 . ')';
    }

    protected function getDateArithmeticIntervalExpression($date, $operator, $interval, $unit): string
    {
        switch ($unit) {
            case DateIntervalUnit::WEEK:
                $interval *= 7;
                $unit = DateIntervalUnit::DAY;
                break;

            case DateIntervalUnit::QUARTER:
                $interval *= 3;
                $unit = DateIntervalUnit::MONTH;
                break;
        }

        return $date . ' ' . $operator . ' ' . $interval . ' ' . $unit;
    }

    public function getDateDiffExpression($date1, $date2): string
    {
        return 'DAYS(' . $date1 . ') - DAYS(' . $date2 . ')';
    }

    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        if (isset($column['version']) && $column['version']) {
            return "TIMESTAMP(0) WITH DEFAULT";
        }

        return 'TIMESTAMP(0)';
    }

    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIME';
    }

    public function getTruncateTableSQL($tableName, $cascade = false): string
    {
        $tableIdentifier = new Identifier($tableName);

        return 'TRUNCATE ' . $tableIdentifier->getQuotedName($this) . ' IMMEDIATE';
    }

    /**
     * This code fragment is originally from the Zend_Db_Adapter_Db2 class, but has been edited.
     *
     * @license New BSD License
     *
     * @param string $table
     * @param string $database
     *
     * @return string
     */
    public function getListTableColumnsSQL($table, $database = null): string
    {
        $table = $this->quoteStringLiteral($table);

        // We do the funky subquery and join syscat.columns.default this crazy way because
        // as of db2 v10, the column is CLOB(64k) and the distinct operator won't allow a CLOB,
        // it wants shorter stuff like a varchar.
        return "
        SELECT
          cols.default,
          subq.*
        FROM (
               SELECT DISTINCT
                 c.tabschema,
                 c.tabname,
                 c.colname,
                 c.colno,
                 c.typename,
                 c.nulls,
                 c.length,
                 c.scale,
                 c.identity,
                 tc.type AS tabconsttype,
                 c.remarks AS comment,
                 k.colseq,
                 CASE
                 WHEN c.generated = 'D' THEN 1
                 ELSE 0
                 END     AS autoincrement
               FROM syscat.columns c
                 LEFT JOIN (syscat.keycoluse k JOIN syscat.tabconst tc
                     ON (k.tabschema = tc.tabschema
                         AND k.tabname = tc.tabname
                         AND tc.type = 'P'))
                   ON (c.tabschema = k.tabschema
                       AND c.tabname = k.tabname
                       AND c.colname = k.colname)
               WHERE UPPER(c.tabname) = UPPER(" . $table . ")
               ORDER BY c.colno
             ) subq
          JOIN syscat.columns cols
            ON subq.tabschema = cols.tabschema
               AND subq.tabname = cols.tabname
               AND subq.colno = cols.colno
        ORDER BY subq.colno
        ";
    }

    public function getListTablesSQL(): string
    {
        return "SELECT NAME FROM SYSIBM.SYSTABLES WHERE TYPE = 'T'";
    }

    public function getListViewsSQL($database): string
    {
        return "SELECT NAME, TEXT FROM SYSIBM.SYSVIEWS";
    }

    public function getListTableIndexesSQL($table, $database = null): string
    {
        $table = $this->quoteStringLiteral($table);

        return "SELECT   idx.INDNAME AS key_name,
                         idxcol.COLNAME AS column_name,
                         CASE
                             WHEN idx.UNIQUERULE = 'P' THEN 1
                             ELSE 0
                         END AS primary,
                         CASE
                             WHEN idx.UNIQUERULE = 'D' THEN 1
                             ELSE 0
                         END AS non_unique
                FROM     SYSCAT.INDEXES AS idx
                JOIN     SYSCAT.INDEXCOLUSE AS idxcol
                ON       idx.INDSCHEMA = idxcol.INDSCHEMA AND idx.INDNAME = idxcol.INDNAME
                WHERE    idx.TABNAME = UPPER(" . $table . ")
                ORDER BY idxcol.COLSEQ ASC";
    }

    public function getListTableForeignKeysSQL($table): string
    {
        $table = $this->quoteStringLiteral($table);

        return "SELECT   fkcol.COLNAME AS local_column,
                         fk.REFTABNAME AS foreign_table,
                         pkcol.COLNAME AS foreign_column,
                         fk.CONSTNAME AS index_name,
                         CASE
                             WHEN fk.UPDATERULE = 'R' THEN 'RESTRICT'
                             ELSE NULL
                         END AS on_update,
                         CASE
                             WHEN fk.DELETERULE = 'C' THEN 'CASCADE'
                             WHEN fk.DELETERULE = 'N' THEN 'SET NULL'
                             WHEN fk.DELETERULE = 'R' THEN 'RESTRICT'
                             ELSE NULL
                         END AS on_delete
                FROM     SYSCAT.REFERENCES AS fk
                JOIN     SYSCAT.KEYCOLUSE AS fkcol
                ON       fk.CONSTNAME = fkcol.CONSTNAME
                AND      fk.TABSCHEMA = fkcol.TABSCHEMA
                AND      fk.TABNAME = fkcol.TABNAME
                JOIN     SYSCAT.KEYCOLUSE AS pkcol
                ON       fk.REFKEYNAME = pkcol.CONSTNAME
                AND      fk.REFTABSCHEMA = pkcol.TABSCHEMA
                AND      fk.REFTABNAME = pkcol.TABNAME
                WHERE    fk.TABNAME = UPPER(" . $table . ")
                ORDER BY fkcol.COLSEQ ASC";
    }

    public function getCreateViewSQL($name, $sql): string
    {
        return "CREATE VIEW ".$name." AS ".$sql;
    }

    public function getDropViewSQL($name): string
    {
        return "DROP VIEW ".$name;
    }

    public function getCreateDatabaseSQL($database): string
    {
        return "CREATE DATABASE ".$database;
    }

    public function getDropDatabaseSQL($database): string
    {
        return "DROP DATABASE " . $database;
    }

    public function supportsCreateDropDatabase(): bool
    {
        return false;
    }

    public function supportsReleaseSavepoints(): bool
    {
        return false;
    }

    public function supportsCommentOnStatement(): bool
    {
        return true;
    }

    public function getCurrentDateSQL(): string
    {
        return 'CURRENT DATE';
    }

    public function getCurrentTimeSQL(): string
    {
        return 'CURRENT TIME';
    }

    public function getCurrentTimestampSQL(): string
    {
        return "CURRENT TIMESTAMP";
    }

    public function getIndexDeclarationSQL($name, Index $index): string
    {
        // Index declaration in statements like CREATE TABLE is not supported.
        throw DBALException::notSupported(__METHOD__);
    }

    protected function _getCreateTableSQL($tableName, array $columns, array $options = array()): array
    {
        $indexes = array();
        if (isset($options['indexes'])) {
            $indexes = $options['indexes'];
        }
        $options['indexes'] = array();

        $sqls = parent::_getCreateTableSQL($tableName, $columns, $options);

        foreach ($indexes as $definition) {
            $sqls[] = $this->getCreateIndexSQL($definition, $tableName);
        }
        return $sqls;
    }

    public function getAlterTableSQL(TableDiff $diff): array
    {
        $sql = array();
        $columnSql = array();
        $commentsSQL = array();

        $queryParts = array();
        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $columnDef = $column->toArray();
            $queryPart = 'ADD COLUMN ' . $this->getColumnDeclarationSQL($column->getQuotedName($this), $columnDef);

            // Adding non-nullable columns to a table requires a default value to be specified.
            if ( ! empty($columnDef['notnull']) &&
                ! isset($columnDef['default']) &&
                empty($columnDef['autoincrement'])
            ) {
                $queryPart .= ' WITH DEFAULT';
            }

            $queryParts[] = $queryPart;

            $comment = $this->getColumnComment($column);

            if (null !== $comment && '' !== $comment) {
                $commentsSQL[] = $this->getCommentOnColumnSQL(
                    $diff->getName($this)->getQuotedName($this),
                    $column->getQuotedName($this),
                    $comment
                );
            }
        }

        foreach ($diff->removedColumns as $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }

            $queryParts[] =  'DROP COLUMN ' . $column->getQuotedName($this);
        }

        foreach ($diff->changedColumns as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            if ($columnDiff->hasChanged('comment')) {
                $commentsSQL[] = $this->getCommentOnColumnSQL(
                    $diff->getName($this)->getQuotedName($this),
                    $columnDiff->column->getQuotedName($this),
                    $this->getColumnComment($columnDiff->column)
                );

                if (count($columnDiff->changedProperties) === 1) {
                    continue;
                }
            }

            $this->gatherAlterColumnSQL($diff->fromTable, $columnDiff, $sql, $queryParts);
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = new Identifier($oldColumnName);

            $queryParts[] =  'RENAME COLUMN ' . $oldColumnName->getQuotedName($this) .
                ' TO ' . $column->getQuotedName($this);
        }

        $tableSql = array();

        if ( ! $this->onSchemaAlterTable($diff, $tableSql)) {
            if (count($queryParts) > 0) {
                $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getQuotedName($this) . ' ' . implode(" ", $queryParts);
            }

            // Some table alteration operations require a table reorganization.
            if ( ! empty($diff->removedColumns) || ! empty($diff->changedColumns)) {
                $sql[] = "CALL SYSPROC.ADMIN_CMD ('REORG TABLE " . $diff->getName($this)->getQuotedName($this) . "')";
            }

            $sql = array_merge($sql, $commentsSQL);

            if ($diff->newName !== false) {
                $sql[] =  'RENAME TABLE ' . $diff->getName($this)->getQuotedName($this) . ' TO ' . $diff->getNewName()->getQuotedName($this);
            }

            $sql = array_merge(
                $this->getPreAlterTableIndexForeignKeySQL($diff),
                $sql,
                $this->getPostAlterTableIndexForeignKeySQL($diff)
            );
        }

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * Gathers the table alteration SQL for a given column diff.
     *
     * @param Table      $table      The table to gather the SQL for.
     * @param ColumnDiff $columnDiff The column diff to evaluate.
     * @param array      $sql        The sequence of table alteration statements to fill.
     * @param array      $queryParts The sequence of column alteration clauses to fill.
     */
    private function gatherAlterColumnSQL(Table $table, ColumnDiff $columnDiff, array &$sql, array &$queryParts)
    {
        $alterColumnClauses = $this->getAlterColumnClausesSQL($columnDiff);

        if (empty($alterColumnClauses)) {
            return;
        }

        // If we have a single column alteration, we can append the clause to the main query.
        if (count($alterColumnClauses) === 1) {
            $queryParts[] = current($alterColumnClauses);

            return;
        }

        // We have multiple alterations for the same column,
        // so we need to trigger a complete ALTER TABLE statement
        // for each ALTER COLUMN clause.
        foreach ($alterColumnClauses as $alterColumnClause) {
            $sql[] = 'ALTER TABLE ' . $table->getQuotedName($this) . ' ' . $alterColumnClause;
        }
    }

    /**
     * Returns the ALTER COLUMN SQL clauses for altering a column described by the given column diff.
     *
     * @param ColumnDiff $columnDiff The column diff to evaluate.
     *
     * @return array
     */
    private function getAlterColumnClausesSQL(ColumnDiff $columnDiff): array
    {
        $column = $columnDiff->column->toArray();

        $alterClause = 'ALTER COLUMN ' . $columnDiff->column->getQuotedName($this);

        if ($column['columnDefinition']) {
            return array($alterClause . ' ' . $column['columnDefinition']);
        }

        $clauses = array();

        if ($columnDiff->hasChanged('type') ||
            $columnDiff->hasChanged('length') ||
            $columnDiff->hasChanged('precision') ||
            $columnDiff->hasChanged('scale') ||
            $columnDiff->hasChanged('fixed')
        ) {
            $clauses[] = $alterClause . ' SET DATA TYPE ' . $column['type']->getSQLDeclaration($column, $this);
        }

        if ($columnDiff->hasChanged('notnull')) {
            $clauses[] = $column['notnull'] ? $alterClause . ' SET NOT NULL' : $alterClause . ' DROP NOT NULL';
        }

        if ($columnDiff->hasChanged('default')) {
            if (isset($column['default'])) {
                $defaultClause = $this->getDefaultValueDeclarationSQL($column);

                if ($defaultClause) {
                    $clauses[] = $alterClause . ' SET' . $defaultClause;
                }
            } else {
                $clauses[] = $alterClause . ' DROP DEFAULT';
            }
        }

        return $clauses;
    }

    protected function getPreAlterTableIndexForeignKeySQL(TableDiff $diff): array
    {
        $sql = array();
        $table = $diff->getName($this)->getQuotedName($this);

        foreach ($diff->removedIndexes as $remKey => $remIndex) {
            foreach ($diff->addedIndexes as $addKey => $addIndex) {
                if ($remIndex->getColumns() == $addIndex->getColumns()) {
                    if ($remIndex->isPrimary()) {
                        $sql[] = 'ALTER TABLE ' . $table . ' DROP PRIMARY KEY';
                    } elseif ($remIndex->isUnique()) {
                        $sql[] = 'ALTER TABLE ' . $table . ' DROP UNIQUE ' . $remIndex->getQuotedName($this);
                    } else {
                        $sql[] = $this->getDropIndexSQL($remIndex, $table);
                    }

                    $sql[] = $this->getCreateIndexSQL($addIndex, $table);

                    unset($diff->removedIndexes[$remKey]);
                    unset($diff->addedIndexes[$addKey]);

                    break;
                }
            }
        }

        $sql = array_merge($sql, parent::getPreAlterTableIndexForeignKeySQL($diff));

        return $sql;
    }

    protected function getRenameIndexSQL($oldIndexName, Index $index, $tableName): array
    {
        if (strpos($tableName, '.') !== false) {
            [$schema] = explode('.', $tableName);
            $oldIndexName = $schema . '.' . $oldIndexName;
        }

        return array('RENAME INDEX ' . $oldIndexName . ' TO ' . $index->getQuotedName($this));
    }

    public function getDefaultValueDeclarationSQL($field): string
    {
        if ( ! empty($field['autoincrement'])) {
            return '';
        }

        if (isset($field['version']) && $field['version']) {
            if ((string) $field['type'] != "DateTime") {
                $field['default'] = "1";
            }
        }

        return parent::getDefaultValueDeclarationSQL($field);
    }

    public function getEmptyIdentityInsertSQL($tableName, $identifierColumnName): string
    {
        return 'INSERT INTO ' . $tableName . ' (' . $identifierColumnName . ') VALUES (DEFAULT)';
    }

    public function getCreateTemporaryTableSnippetSQL(): string
    {
        return "DECLARE GLOBAL TEMPORARY TABLE";
    }

    public function getTemporaryTableName($tableName): string
    {
        return "SESSION." . $tableName;
    }

    protected function doModifyLimitQuery($query, $limit, $offset = null): string
    {
        $where = array();

        if ($offset > 0) {
            $where[] = sprintf('db22.DC_ROWNUM >= %d', $offset + 1);
        }

        if ($limit !== null) {
            $where[] = sprintf('db22.DC_ROWNUM <= %d', $offset + $limit);
        }

        if (empty($where)) {
            return $query;
        }

        // Todo OVER() needs ORDER BY data!
        return sprintf(
            'SELECT db22.* FROM (SELECT db21.*, ROW_NUMBER() OVER() AS DC_ROWNUM FROM (%s) db21) db22 WHERE %s',
            $query,
            implode(' AND ', $where)
        );
    }

    public function getLocateExpression($str, $substr, $startPos = false): string
    {
        if ($startPos == false) {
            return 'LOCATE(' . $substr . ', ' . $str . ')';
        }

        return 'LOCATE(' . $substr . ', ' . $str . ', '.$startPos.')';
    }

    public function getSubstringExpression($value, $from, $length = null): string
    {
        if ($length === null) {
            return 'SUBSTR(' . $value . ', ' . $from . ')';
        }

        return 'SUBSTR(' . $value . ', ' . $from . ', ' . $length . ')';
    }

    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    public function prefersIdentityColumns(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * DB2 returns all column names in SQL result sets in uppercase.
     */
    public function getSQLResultCasing($column): string
    {
        return strtoupper($column);
    }

    public function getForUpdateSQL(): string
    {
        return ' WITH RR USE AND KEEP UPDATE LOCKS';
    }

    public function getDummySelectSQL(): string
    {
        return 'SELECT 1 FROM sysibm.sysdummy1';
    }

    public function supportsSavepoints(): bool
    {
        return false;
    }

    protected function getReservedKeywordsClass(): string
    {
        return 'Doctrine\DBAL\Platforms\Keywords\DB2Keywords';
    }

}
