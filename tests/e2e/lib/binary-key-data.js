export async function createBinaryKeyData(conn) {
    await conn.query(`
CREATE TABLE aa_binary_primary_keys (
    id VARBINARY(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    payload BLOB NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;
    `);
    await conn.query(`
CREATE TABLE ab_composite_binary_primary_key (
    tenant VARBINARY(32) NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    suffix VARBINARY(32) NOT NULL,
    payload BLOB NOT NULL,
    PRIMARY KEY (tenant, sequence, suffix)
) ENGINE=InnoDB;

    `);

    const singleKeyRows = [
        [Buffer.alloc(0), 'empty key', Buffer.from('00ff80', 'hex')],
        [Buffer.from('00', 'hex'), 'NUL key', Buffer.from('000102ff', 'hex')],
        [Buffer.from('7f', 'hex'), 'ASCII boundary', Buffer.from('quotes-\'"\\\\\n')],
        [Buffer.from('80', 'hex'), 'continuation byte', bytePattern(257, 3)],
        [Buffer.from('c0af', 'hex'), 'overlong UTF-8', bytePattern(513, 7)],
        [Buffer.from('eda080', 'hex'), 'UTF-8 surrogate', bytePattern(1025, 11)],
        [Buffer.from('f0288cbc', 'hex'), 'invalid four-byte UTF-8', bytePattern(2049, 13)],
        [Buffer.from('fffefdfc0080', 'hex'), 'high and NUL bytes', bytePattern(4097, 17)],
    ];
    for (const row of singleKeyRows) {
        await conn.query(
            'INSERT INTO aa_binary_primary_keys (id, label, payload) VALUES (?, ?, ?)',
            row,
        );
    }

    const compositeKeyRows = [
        [Buffer.from('00ff', 'hex'), '0', Buffer.alloc(0), Buffer.from('first')],
        [Buffer.from('00ff', 'hex'), '18446744073709551615', Buffer.from('80', 'hex'), bytePattern(300, 19)],
        [Buffer.from('80', 'hex'), '42', Buffer.from('00ff00', 'hex'), bytePattern(600, 23)],
        [Buffer.from('fffe', 'hex'), '42', Buffer.from('c0af', 'hex'), bytePattern(900, 29)],
        [Buffer.from('fffe', 'hex'), '43', Buffer.from('fffefd', 'hex'), bytePattern(1200, 31)],
    ];
    for (const row of compositeKeyRows) {
        await conn.query(
            'INSERT INTO ab_composite_binary_primary_key ' +
                '(tenant, sequence, suffix, payload) VALUES (?, ?, ?, ?)',
            row,
        );
    }

}

export async function createOversizedBinaryKeyData(conn) {
    await conn.query(`
CREATE TABLE ac_oversized_binary_primary_key (
    id VARBINARY(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    payload LONGBLOB NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB;
    `);
    await conn.query(
        'INSERT INTO ac_oversized_binary_primary_key (id, label, payload) VALUES (?, ?, ?)',
        [
            Buffer.from('80ff00fe', 'hex'),
            'oversized binary row',
            bytePattern(2 * 1024 * 1024 + 17, 37),
        ],
    );
    await conn.query(
        'INSERT INTO ac_oversized_binary_primary_key (id, label, payload) VALUES (?, ?, ?)',
        [
            Buffer.from('fffefdfc', 'hex'),
            'row after oversized key',
            Buffer.from('00ff80fefdc0af', 'hex'),
        ],
    );
}

function bytePattern(length, seed) {
    const bytes = Buffer.allocUnsafe(length);
    for (let index = 0; index < length; index++) {
        bytes[index] = (index * 131 + seed) % 256;
    }
    return bytes;
}
