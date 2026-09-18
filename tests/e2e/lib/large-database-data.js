export async function createManyRowData(connection, firstTable, rowCount = 1001) {
    await connection.query(
        `CREATE TABLE \`${firstTable}\` (`
        + '`id` INT NOT NULL PRIMARY KEY, `value` VARCHAR(64) NOT NULL) ENGINE=InnoDB'
    );
    // With the default 1,001 rows, one table comment plus three complete
    // 250-row INSERTs use 751 fragments. A 1,000-fragment batch then ends after 249
    // rows of the fourth INSERT, leaving an unfinished suffix.
    const rows = Array.from({ length: rowCount }, (_, index) => [
        index + 1,
        `row-${index + 1}`,
    ]);
    for (let offset = 0; offset < rows.length; offset += 100) {
        await connection.query(
            `INSERT INTO \`${firstTable}\` (id, value) VALUES ?`,
            [rows.slice(offset, offset + 100)],
        );
    }
}

export async function createBoundedPayloadData(connection, boundedPayloadTable) {
    await connection.query(
        `CREATE TABLE \`${boundedPayloadTable}\` (`
        + '`id` INT NOT NULL PRIMARY KEY, `payload` MEDIUMBLOB NOT NULL) ENGINE=InnoDB'
    );
    // These 200 rows remain inside one 250-row query batch, but
    // their formatted SQL is about 21 MiB. The producer must close
    // the INSERT on bytes before the query batch ends.
    for (let id = 1; id <= 200; id++) {
        await connection.query(
            `INSERT INTO \`${boundedPayloadTable}\` (id, payload) `
            + 'VALUES (?, REPEAT(CHAR(65 + MOD(?, 26)), 80 * 1024))',
            [id, id],
        );
    }
}

export async function createLargeSingleRowData(connection, splitPayloadTable) {
    await connection.query(
        `CREATE TABLE \`${splitPayloadTable}\` (`
        + '`id` INT NOT NULL PRIMARY KEY, '
        + '`payload` MEDIUMBLOB NOT NULL) ENGINE=InnoDB'
    );
    await connection.query(
        `INSERT INTO \`${splitPayloadTable}\` `
        + '(id, payload) VALUES ('
        + '1, REPEAT(CHAR(65), 13 * 1024 * 1024))'
    );
}
