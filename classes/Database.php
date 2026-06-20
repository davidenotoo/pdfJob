<?php

declare(strict_types=1);

/**
 * Database.php
 * -----------------------------------------------------------------------
 * Wrapper PDO (singleton) per la connessione a my_testjobstatus.
 * Espone inoltre un helper per leggere dinamicamente le colonne di una
 * tabella: e' cosi' che la "Libreria Campi" nella sidebar dell'editor
 * resta sempre sincronizzata con la struttura reale del database, senza
 * dover hardcodare i nomi delle variabili nel frontend.
 * -----------------------------------------------------------------------
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
        // Non istanziabile direttamente: usare Database::getConnection()
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                $msg = APP_DEBUG
                    ? 'Connessione al database fallita: ' . $e->getMessage()
                    : 'Connessione al database fallita. Verifica le credenziali in config.php.';
                die($msg);
            }
        }

        return self::$instance;
    }

    /**
     * Restituisce l'elenco delle colonne di una tabella nel formato
     * [ ['name' => 'customer_name', 'type' => 'varchar(150)'], ... ]
     * usando INFORMATION_SCHEMA (non viene mai costruita una query con il
     * nome tabella concatenato a stringhe esterne, e' sempre un parametro
     * bindato).
     *
     * @return array<int, array{name:string,type:string}>
     */
    public static function getTableColumns(string $table): array
    {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION ASC'
        );
        $stmt->execute([
            ':schema' => DB_NAME,
            ':table'  => $table,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Verifica rapida (whitelist) che una colonna esista realmente sulla
     * tabella indicata. Usata da save_template.php per impedire che un
     * payload malevolo associ un campo PDF a una colonna arbitraria del
     * database (es. password, hash, colonne di altre tabelle).
     */
    public static function tableHasColumn(string $table, string $column): bool
    {
        foreach (self::getTableColumns($table) as $col) {
            if ($col['name'] === $column) {
                return true;
            }
        }
        return false;
    }
}
