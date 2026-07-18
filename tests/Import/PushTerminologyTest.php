<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * Keeps push identifiers aligned with markdown/PUSH-TERMINOLOGY.md.
 */
final class PushTerminologyTest extends TestCase {
    public function testPushFilesSenderUsesGlossaryNames(): void
    {
        $sender = file_get_contents(
            __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-files-sender.php'
        );
        $glossary = file_get_contents(__DIR__ . '/../../markdown/PUSH-TERMINOLOGY.md');
        $this->assertIsString($sender);
        $this->assertIsString($glossary);

        $required_names = [
            'push_state_directory',
            'LocalPathToPush',
            'local_path_to_push',
            'read_local_path_to_push',
            'push_stream_client',
            'create_push_stream_client',
            'push_stream_client_options',
            'request_sizer_options',
            'send_push_request',
            'create_push_session',
            'upload_next_file_chunk',
            'upload_next_chunk_of_deleted_paths',
            'local_path_type_size_and_ctime',
            'stat_local_path',
            'request_result',
            'failure_result',
            'plan_result',
            'receiver_path_status',
            'receiver_path_type',
            'receiver_confirmed_bytes',
            'upload_completes_local_path',
            'maximum_file_payload_bytes',
            'maximum_delete_list_payload_bytes',
            'local_io_failure_detail',
            'local_delete_list_complete',
            'directory_handle',
            'local_paths_to_push_handle',
            'local_paths_to_delete_handle',
            'local_file_handle',
            'path_stat',
            'file_type_bits',
            'upload_request_start_failure',
            'delete_state',
        ];

        foreach ($required_names as $required_name) {
            $this->assertStringContainsString($required_name, $sender);
            $this->assertStringContainsString($required_name, $glossary);
        }
    }

    public function testRetiredPushTermsDoNotReturn(): void
    {
        $paths = [
            __DIR__ . '/../../AGENTS.md',
            __DIR__ . '/../../markdown/PUSH-SYNC.md',
            __DIR__ . '/../../markdown/PUSH-TERMINOLOGY.md',
            __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-files-sender.php',
            __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-plan.php',
            __DIR__ . '/../../packages/reprint-importer/src/lib/upload/class-multipart-push-stream-client.php',
            __DIR__ . '/MultipartPushStreamClientTest.php',
            __DIR__ . '/../PushEndpointsTest.php',
        ];
        $retired_patterns = [
            '/positive[-_ ]work/i',
            '/positive local work/i',
            '/\bsite_dir\b/',
            '/\brequest_sizer_config\b/',
            '/control[_ -]?request/i',
            '/\bSelectedLocalPath\b/',
            '/\bselected_local_path\b/',
            '/\bread_selected_path\b/',
            '/\blogical_value_complete\b/',
            '/\bmaximum_payload_bytes\b/',
            '/\blocal_failure_detail\b/',
            '/\blocal_paths_to_delete_complete\b/',
            '/\brecoverable_failures\b/',
            '/\bMAXIMUM_RECOVERABLE_FAILURES\b/',
            '/\bconsecutive_recoverable_failures\b/',
            '/\bMAXIMUM_CONSECUTIVE_RECOVERABLE_FAILURES\b/',
            '/\bupload_start_failure\b/',
            '/\bclear_state\b/',
            '/\bnext_local_path_upload_part\b/',
            '/\bnext_delete_list_upload_part\b/',
            '/\bcreate_push\b/',
            '/local[_ ]path[_ ]change[_ ]fields/i',
            '/\bLocalPathChangeFields\b/',
            '/\bread_local_path_type_size_and_ctime\b/',
            '/remote[_ -]?session/i',
            '/remote[_ -]?workflow/i',
            '/site[_ -]?lock/i',
            '/sender[_ -]?lock/i',
        ];
        $retired_sender_patterns = [
            '/\$client\b/',
            '/\$request\b/',
            '/\$failure\b/',
            '/\$planning\b/',
            '/\$confirmed_bytes\b/',
            '/\$path_status\b/',
            '/\$path_type\b/',
            '/\$directory\b/',
            '/\$identity\b/',
            '/\$kind\b/',
            '/record/i',
            '/field/i',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            $this->assertIsString($contents, 'Failed to read ' . $path);

            foreach ($retired_patterns as $retired_pattern) {
                $this->assertSame(
                    0,
                    preg_match($retired_pattern, $contents),
                    "Retired push term {$retired_pattern} found in {$path}."
                );
            }
        }

        $sender_path = __DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-files-sender.php';
        $sender = file_get_contents($sender_path);
        $this->assertIsString($sender, 'Failed to read ' . $sender_path);

        foreach ($retired_sender_patterns as $retired_sender_pattern) {
            $this->assertSame(
                0,
                preg_match($retired_sender_pattern, $sender),
                "Retired PushFilesSender term {$retired_sender_pattern} found in {$sender_path}."
            );
        }
    }
}
