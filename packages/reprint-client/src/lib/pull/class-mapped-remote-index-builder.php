<?php

use function Reprint\Importer\sort_index_file;
use function WordPress\Reprint\Exporter\path_is_descendant_of;
use function WordPress\Reprint\Exporter\path_remainder_under;
use function WordPress\Reprint\Exporter\relative_path_under;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Filesystem paths are CLI values, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/** Builds the current remote index in mapped local path order. */
final class MappedRemoteIndexBuilder
{
    /**
     * Rebuilds, sorts, and validates one mapped remote index.
     *
     * The output is disposable until this method returns. A caller may rebuild
     * it from the immutable remote index after interruption.
     *
     * @param array $options {
     *     Inputs and output for the mapped index.
     *
     *     @type string                  $remote_index_file        Completed remote index.
     *     @type string                  $mapped_remote_index_file Output in mapped local order.
     *     @type string                  $filesystem_root          Local filesystem root.
     *     @type RemoteToLocalPathMapper $path_mapper              Remote-to-local path mapper.
     *     @type list<string>            $excluded_remote_absolute_path_prefixes Remote prefixes omitted from the mapped index. Default empty.
     * }
     */
    public static function build(array $options): void
    {
        $allowed_options = [
            "remote_index_file",
            "mapped_remote_index_file",
            "filesystem_root",
            "path_mapper",
            "excluded_remote_absolute_path_prefixes",
        ];
        $unknown_options = array_diff(array_keys($options), $allowed_options);
        if ($unknown_options !== []) {
            throw new InvalidArgumentException(
                "Unknown mapped remote-index option: " . reset($unknown_options)
            );
        }
        foreach (
            ["remote_index_file", "mapped_remote_index_file", "filesystem_root"]
            as $string_option
        ) {
            if (
                !isset($options[$string_option])
                || !is_string($options[$string_option])
                || $options[$string_option] === ""
            ) {
                $observed_type = gettype($options[$string_option] ?? null);
                throw new InvalidArgumentException(
                    "Mapped remote-index option {$string_option} must be a non-empty string; "
                    . "received {$observed_type}."
                );
            }
        }
        if (
            !isset($options["path_mapper"])
            || !( $options["path_mapper"] instanceof RemoteToLocalPathMapper )
        ) {
            $observed_type = gettype($options["path_mapper"] ?? null);
            throw new InvalidArgumentException(
                "Mapped remote-index option path_mapper must be a RemoteToLocalPathMapper; "
                . "received {$observed_type}."
            );
        }

        /** @var string $remote_index_file */
        $remote_index_file = $options["remote_index_file"];
        /** @var string $mapped_remote_index_file */
        $mapped_remote_index_file = $options["mapped_remote_index_file"];
        /** @var string $filesystem_root */
        $filesystem_root = $options["filesystem_root"];
        /** @var RemoteToLocalPathMapper $path_mapper */
        $path_mapper = $options["path_mapper"];
        $excluded_remote_absolute_path_prefixes =
            $options["excluded_remote_absolute_path_prefixes"] ?? [];
        if (
            !is_array($excluded_remote_absolute_path_prefixes)
            || array_values($excluded_remote_absolute_path_prefixes)
                !== $excluded_remote_absolute_path_prefixes
        ) {
            throw new InvalidArgumentException(
                "Mapped remote-index option excluded_remote_absolute_path_prefixes "
                . "must be a list of strings."
            );
        }
        foreach ($excluded_remote_absolute_path_prefixes as $excluded_prefix) {
            if (!is_string($excluded_prefix) || $excluded_prefix === "") {
                $observed_type = gettype($excluded_prefix);
                throw new InvalidArgumentException(
                    "Mapped remote-index option excluded_remote_absolute_path_prefixes "
                    . "must contain non-empty strings; received {$observed_type}."
                );
            }
        }

        $remote_index_reader = new RemoteIndexReader($remote_index_file);
        $mapped_index_handle = fopen($mapped_remote_index_file, "wb");
        if (!is_resource($mapped_index_handle)) {
            throw new RuntimeException(
                "Failed to create the mapped remote index: {$mapped_remote_index_file}."
            );
        }
        try {
            $remote_index_reader->open();
            $remote_entry = $remote_index_reader->next_entry();
            while ($remote_entry !== null) {
                if (
                    !self::path_is_excluded(
                        $remote_entry["path"],
                        $excluded_remote_absolute_path_prefixes
                    )
                ) {
                    self::write_mapped_entry(
                        $mapped_index_handle,
                        $remote_entry,
                        $filesystem_root,
                        $path_mapper
                    );
                }
                $remote_entry = $remote_index_reader->next_entry();
            }
            if (!fflush($mapped_index_handle)) {
                throw new RuntimeException(
                    "Failed to flush the mapped remote index: {$mapped_remote_index_file}."
                );
            }
        } finally {
            $remote_index_reader->close();
            fclose($mapped_index_handle);
        }

        if (!sort_index_file($mapped_remote_index_file)) {
            throw new RuntimeException(
                "Failed to sort the mapped remote index: {$mapped_remote_index_file}."
            );
        }
        self::assert_no_path_collisions($mapped_remote_index_file);
    }

    /** @param list<string> $excluded_remote_absolute_path_prefixes */
    private static function path_is_excluded(
        string $remote_absolute_path,
        array $excluded_remote_absolute_path_prefixes
    ): bool {
        foreach ($excluded_remote_absolute_path_prefixes as $excluded_prefix) {
            if (path_remainder_under($remote_absolute_path, $excluded_prefix) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Decodes one mapped remote entry for a local-path index merge.
     *
     * @return array {
     *     @type string $path             Local relative path.
     *     @type string $type             file, link, or dir.
     *     @type int    $ctime            Always zero; clocks are not compared.
     *     @type int    $size             Remote size.
     *     @type string $copy_source_path Remote absolute path.
     * }
     * @phpstan-return array{path:string,type:'file'|'link'|'dir',ctime:int,size:int,copy_source_path:string}
     */
    public static function decode_index_line(string $line): array
    {
        $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $encoded_mapping = is_array($entry) ? $entry["path"] ?? null : null;
        $mapping = is_string($encoded_mapping)
            ? base64_decode($encoded_mapping, true)
            : false;
        $separator = is_string($mapping) ? strpos($mapping, "/") : false;
        $local_relative_path = $separator === false
            ? false
            : @hex2bin(substr($mapping, 0, $separator));
        $remote_absolute_path = $separator === false
            ? false
            : @hex2bin(substr($mapping, $separator + 1));
        $type = is_array($entry) ? $entry["type"] ?? null : null;
        if (
            !is_string($local_relative_path)
            || $local_relative_path === ""
            || !is_string($remote_absolute_path)
            || $remote_absolute_path === ""
            || !in_array($type, ["file", "dir", "link"], true)
        ) {
            throw new RuntimeException("Invalid mapped remote-index entry.");
        }
        return [
            "path" => $local_relative_path,
            "type" => $type,
            "ctime" => 0,
            "size" => (int) ( $entry["size"] ?? 0 ),
            "copy_source_path" => $remote_absolute_path,
        ];
    }

    /** @param resource $mapped_index_handle */
    private static function write_mapped_entry(
        $mapped_index_handle,
        array $remote_entry,
        string $filesystem_root,
        RemoteToLocalPathMapper $path_mapper
    ): void {
        $remote_absolute_path = $remote_entry["path"];
        $local_relative_path = relative_path_under(
            $path_mapper->remote_path_to_local_path($remote_absolute_path),
            $filesystem_root
        );
        if ($local_relative_path === null) {
            throw new RuntimeException(
                "Mapped remote path is outside the filesystem root: {$remote_absolute_path}."
            );
        }
        if ($local_relative_path === "") {
            if ($remote_entry["type"] !== "dir") {
                throw new RuntimeException(
                    "A remote path cannot replace the filesystem root: {$remote_absolute_path}."
                );
            }
            return;
        }

        $mapped_key = bin2hex($local_relative_path)
            . "/"
            . bin2hex($remote_absolute_path);
        $line = json_encode(
            [
                "path" => base64_encode($mapped_key),
                "ctime" => 0,
                "size" => $remote_entry["size"],
                "type" => $remote_entry["type"],
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (fwrite($mapped_index_handle, $line) !== strlen($line)) {
            throw new RuntimeException("Failed to write the mapped remote index.");
        }
    }

    /** Rejects remote paths which map to the same local path or below one. */
    private static function assert_no_path_collisions(
        string $mapped_remote_index_file
    ): void {
        $mapped_index_handle = fopen($mapped_remote_index_file, "rb");
        if (!is_resource($mapped_index_handle)) {
            throw new RuntimeException(
                "Failed to validate the mapped remote index: {$mapped_remote_index_file}."
            );
        }
        $collision_stack_file = $mapped_remote_index_file . ".collision-stack";
        $collision_stack_handle = fopen($collision_stack_file, "w+b");
        if (!is_resource($collision_stack_handle)) {
            fclose($mapped_index_handle);
            throw new RuntimeException(
                "Failed to create the mapped-path collision stack: {$collision_stack_file}."
            );
        }
        $preceding_local_path = null;
        /** @var array{path:string,record_offset:int,previous_record_offset:int|null}|null $active_mapped_path */
        $active_mapped_path = null;
        $collision_stack_byte_offset = 0;
        try {
            $line = fgets($mapped_index_handle);
            while ($line !== false) {
                $local_path = self::decode_index_line($line)["path"];
                if ($local_path === $preceding_local_path) {
                    throw new RuntimeException(
                        "Remote paths map to the same local path: {$local_path}."
                    );
                }
                while ($active_mapped_path !== null) {
                    $mapped_path = $active_mapped_path["path"];
                    if (path_is_descendant_of($local_path, $mapped_path)) {
                        throw new RuntimeException(
                            "A remote path maps below another remote path: "
                            . "{$local_path} is below {$mapped_path}."
                        );
                    }
                    if (strcmp($local_path, $mapped_path . "/") <= 0) {
                        break;
                    }
                    $previous_record_offset =
                        $active_mapped_path["previous_record_offset"];
                    if ($previous_record_offset === null) {
                        $active_mapped_path = null;
                        continue;
                    }
                    if (
                        fseek($collision_stack_handle, $previous_record_offset) !== 0
                    ) {
                        throw new RuntimeException(
                            "Failed to seek in the mapped-path collision stack."
                        );
                    }
                    $stack_line = fgets($collision_stack_handle);
                    $stack_record = is_string($stack_line)
                        ? json_decode($stack_line, true, 512, JSON_THROW_ON_ERROR)
                        : null;
                    $stack_path = is_array($stack_record)
                        ? base64_decode($stack_record["path_b64"] ?? "", true)
                        : false;
                    if (!is_string($stack_path)) {
                        throw new RuntimeException(
                            "Invalid mapped-path collision stack record."
                        );
                    }
                    $active_mapped_path = [
                        "path" => $stack_path,
                        "record_offset" => $previous_record_offset,
                        "previous_record_offset" =>
                            $stack_record["previous_record_offset"] ?? null,
                    ];
                }
                $stack_line = json_encode(
                    [
                        "path_b64" => base64_encode($local_path),
                        "previous_record_offset" =>
                            $active_mapped_path["record_offset"] ?? null,
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ) . "\n";
                if (
                    fseek($collision_stack_handle, $collision_stack_byte_offset) !== 0
                    || fwrite($collision_stack_handle, $stack_line)
                        !== strlen($stack_line)
                ) {
                    throw new RuntimeException(
                        "Failed to write the mapped-path collision stack."
                    );
                }
                $active_mapped_path = [
                    "path" => $local_path,
                    "record_offset" => $collision_stack_byte_offset,
                    "previous_record_offset" =>
                        $active_mapped_path["record_offset"] ?? null,
                ];
                $collision_stack_byte_offset += strlen($stack_line);
                $preceding_local_path = $local_path;
                $line = fgets($mapped_index_handle);
            }
            if (!feof($mapped_index_handle)) {
                throw new RuntimeException(
                    "Failed to read the mapped remote index: {$mapped_remote_index_file}."
                );
            }
        } finally {
            fclose($mapped_index_handle);
            fclose($collision_stack_handle);
            @unlink($collision_stack_file);
        }
    }
}
