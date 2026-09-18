export async function createSqlModeData(connection, sqlModeTable) {
    await connection.query(
        `CREATE TABLE \`${sqlModeTable}\` (`
        + "`id` INT NOT NULL, `value` ENUM('allowed') NOT NULL, "
        + 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    await connection.query(
        `INSERT IGNORE INTO \`${sqlModeTable}\` (id, value) `
        + "VALUES (1, 'not-an-enum-member')"
    );
}
