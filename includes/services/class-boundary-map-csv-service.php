<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Csv_Service
{
    public static function csv_headers_to_keys($headers)
    {
        return array_map(function ($header) {
            $header = strtolower(trim((string) $header));
            $header = preg_replace('/[^a-z0-9]+/', '_', $header);

            return trim($header, '_');
        }, (array) $headers);
    }

    public static function parse_uploaded_csv($file_field)
    {
        if (empty($_FILES[$file_field]['tmp_name']) || !is_uploaded_file($_FILES[$file_field]['tmp_name'])) {
            return new WP_Error('missing_file', __('Please choose a CSV file to import.', 'boundary-map'));
        }

        $handle = fopen($_FILES[$file_field]['tmp_name'], 'r');
        if (!$handle) {
            return new WP_Error('invalid_file', __('The uploaded CSV file could not be read.', 'boundary-map'));
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return new WP_Error('empty_file', __('The uploaded CSV file is empty.', 'boundary-map'));
        }

        $keys = self::csv_headers_to_keys($headers);
        $rows = array();

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === array(null) || $row === false) {
                continue;
            }

            $assoc = array();
            foreach ($keys as $index => $key) {
                if ($key === '') {
                    continue;
                }

                $assoc[$key] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }

            $rows[] = $assoc;
        }

        fclose($handle);

        return apply_filters('boundary_map_parsed_csv_rows', $rows, $file_field, $keys);
    }

    public static function send_csv_download($filename, $headers, $rows)
    {
        $headers = apply_filters('boundary_map_csv_export_headers', $headers, $filename, $rows);
        $rows = apply_filters('boundary_map_csv_export_rows', $rows, $filename, $headers);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}
