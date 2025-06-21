<?php
// data latih
function addManualData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['add_manual'])) {
        $tweet = trim($_POST['tweet']);
        $sentiment = $_POST['sentiment'];

        if (empty($tweet) || empty($sentiment)) {
            $error_message = "Tweet dan sentimen harus diisi.";
            $toast_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_latih')");
            $stmt->bind_param("ss", $tweet, $sentiment);

            if ($stmt->execute()) {
                $success_message = "Data berhasil ditambahkan.";
                $toast_type = 'success';
            } else {
                $error_message = "Gagal menambahkan data: " . $conn->error;
                $toast_type = 'error';
            }

            $stmt->close();
        }
    }
}

// Import Data (CSV or Excel)
function importData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['import_file'])) {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] != 0) {
            $error_message = "Terjadi kesalahan saat mengunggah file. Pilih file terlebih dahulu.";
            $toast_type = 'error';
        } else {
            $file = $_FILES['file'];
            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);

            if (strtolower($file_ext) == 'csv') {
                processCsv($conn, $file);
            } elseif (in_array(strtolower($file_ext), ['xlsx', 'xls'])) {
                processExcel($conn, $file, $file_ext);
            } else {
                $error_message = "Format file tidak valid. Harus berformat CSV atau Excel.";
                $toast_type = 'error';
            }
        }
    }
}

// Process CSV File
function processCsv($conn, $file)
{
    global $error_message, $success_message, $toast_type;

    $file_handle = fopen($file['tmp_name'], 'r');
    if (!$file_handle) {
        $error_message = "Gagal membuka file CSV.";
        $toast_type = 'error';
    } else {
        fgetcsv($file_handle); // Skip header

        $imported_count = 0;
        $skipped_count = 0;
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_latih')");

        while (($line = fgetcsv($file_handle)) !== FALSE) {
            if (count($line) >= 2) {
                $tweet = trim($line[0]);
                $sentiment = strtolower(trim($line[1])) == 'positive' || strtolower(trim($line[1])) == 'negative' ? strtolower(trim($line[1])) : '';

                if (!empty($tweet) && !empty($sentiment)) {
                    $stmt->bind_param("ss", $tweet, $sentiment);
                    $stmt->execute();
                    $imported_count++;
                } else {
                    $skipped_count++;
                }
            } else {
                $skipped_count++;
            }
        }

        $conn->commit();
        fclose($file_handle);
        $stmt->close();

        if ($imported_count > 0) {
            $success_message = "$imported_count data berhasil diimpor dari CSV.";
            if ($skipped_count > 0) {
                $success_message .= " ($skipped_count data dilewati karena tidak valid)";
            }
            $toast_type = 'success';
        } else {
            $error_message = "Tidak ada data valid yang dapat diimpor dari file CSV.";
            $toast_type = 'error';
        }
    }
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
// Process Excel File
function processExcel($conn, $file, $file_ext)
{
    global $error_message, $success_message, $toast_type;

    try {
        $reader = ($file_ext === 'xlsx') ? new Xlsx() : new Xls();
        $spreadsheet = $reader->load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) <= 1) {
            $error_message = "File Excel tidak berisi data yang cukup.";
            $toast_type = 'error';
        } else {
            array_shift($rows); // skip header
            $imported_count = 0;
            $skipped_count = 0;

            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_latih')");

            foreach ($rows as $row) {
                if (count($row) >= 2) {
                    $tweet = isset($row[0]) ? trim($row[0]) : '';
                    $sentiment = isset($row[1]) ? strtolower(trim($row[1])) : '';

                    if (!empty($tweet) && !empty($sentiment) && ($sentiment == 'positive' || $sentiment == 'negative')) {
                        $stmt->bind_param("ss", $tweet, $sentiment);
                        $stmt->execute();
                        $imported_count++;
                    } else {
                        $skipped_count++;
                    }
                } else {
                    $skipped_count++;
                }
            }

            $conn->commit();
            $stmt->close();

            if ($imported_count > 0) {
                $success_message = "$imported_count data berhasil diimpor.";
                if ($skipped_count > 0) {
                    $success_message .= " ($skipped_count dilewati)";
                }
                $toast_type = 'success';
            } else {
                $error_message = "Tidak ada data valid yang berhasil diimpor.";
                $toast_type = 'error';
            }
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Gagal membaca file Excel: " . $e->getMessage();
        $toast_type = 'error';
    }
}

// Delete Data
function deleteData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['delete_id']) && is_numeric($_POST['delete_id'])) {
        $id = $_POST['delete_id'];

        $stmt = $conn->prepare("DELETE FROM dataset WHERE id = ? AND type = 'data_latih'");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $success_message = "Data berhasil dihapus.";
            $toast_type = 'success';
        } else {
            $error_message = "Gagal menghapus data: " . $conn->error;
            $toast_type = 'error';
        }

        $stmt->close();
    }
}

// Delete All Data
function deleteAllData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['delete_all'])) {
        // Add a confirmation process if necessary
        $stmt = $conn->prepare("DELETE FROM dataset WHERE type = 'data_latih'");

        if ($stmt->execute()) {
            $success_message = "Semua data berhasil dihapus.";
            $toast_type = 'success';
        } else {
            $error_message = "Gagal menghapus data: " . $conn->error;
            $toast_type = 'error';
        }

        $stmt->close();
    }
}

// Edit Data
function editData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['edit_data'])) {
        $id = $_POST['edit_id'];
        $tweet = trim($_POST['edit_tweet']);
        $sentiment = $_POST['edit_sentiment'];

        if (empty($tweet) || empty($sentiment)) {
            $error_message = "Tweet dan sentimen harus diisi.";
            $toast_type = 'error';
        } else {
            $stmt = $conn->prepare("UPDATE dataset SET tweet = ?, sentiment = ? WHERE id = ? AND type = 'data_latih'");
            $stmt->bind_param("ssi", $tweet, $sentiment, $id);

            if ($stmt->execute()) {
                $success_message = "Data berhasil diperbarui.";
                $toast_type = 'success';
            } else {
                $error_message = "Gagal memperbarui data: " . $conn->error;
                $toast_type = 'error';
            }

            $stmt->close();
        }
    }
}

// Export Data to CSV
function exportCsv($conn)
{
    if (isset($_POST['export_csv'])) {
        $stmt = $conn->prepare("SELECT tweet, sentiment FROM dataset WHERE type = 'data_latih' ORDER BY id");
        $stmt->execute();
        $result = $stmt->get_result();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="data_latih_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Tweet', 'Sentiment']);

        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['tweet'], $row['sentiment']]);
        }

        fclose($output);
        $stmt->close();
        exit;
    }
}

// Fetch dataset for pagination
function fetchDataset($conn, $page, $records_per_page)
{
    global $error_message;

    // Calculate the offset for pagination
    $offset = ($page - 1) * $records_per_page;
    if ($offset < 0) $offset = 0;

    // Query to fetch dataset based on pagination
    $sql = "SELECT id, tweet, sentiment FROM dataset WHERE type = 'data_latih' ORDER BY id DESC LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $offset, $records_per_page);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        $error_message = "Error query: " . $conn->error;
        $dataset = [];
    } else {
        $dataset = $result->fetch_all(MYSQLI_ASSOC);
    }

    return $dataset;
}
// Fetch total pages for pagination
function getTotalPages($conn, $records_per_page)
{
    $sql_total = "SELECT COUNT(*) AS count FROM dataset WHERE type = 'data_latih'";
    $total_result = $conn->query($sql_total);
    $row = $total_result->fetch_assoc();
    $total_records = $row['count'];
    $total_pages = ceil($total_records / $records_per_page);

    return $total_pages;
}
// end data latih


// data uji


// Add Test Data (Data Uji)
function addTestData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['add_test'])) {
        $tweet = trim($_POST['tweet']);
        $sentiment = $_POST['sentiment'];

        if (empty($tweet) || empty($sentiment)) {
            $error_message = "Tweet dan sentimen harus diisi.";
            $toast_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_uji')");
            $stmt->bind_param("ss", $tweet, $sentiment);

            if ($stmt->execute()) {
                $success_message = "Data berhasil ditambahkan.";
                $toast_type = 'success';
            } else {
                $error_message = "Gagal menambahkan data: " . $conn->error;
                $toast_type = 'error';
            }

            $stmt->close();
        }
    }
}

// Import Test Data (CSV or Excel) - Data Uji
function importTestData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['import_test_file'])) {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] != 0) {
            $error_message = "Terjadi kesalahan saat mengunggah file. Pilih file terlebih dahulu.";
            $toast_type = 'error';
        } else {
            $file = $_FILES['file'];
            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);

            if (strtolower($file_ext) == 'csv') {
                processTestCsv($conn, $file);
            } elseif (in_array(strtolower($file_ext), ['xlsx', 'xls'])) {
                processTestExcel($conn, $file, $file_ext);
            } else {
                $error_message = "Format file tidak valid. Harus berformat CSV atau Excel.";
                $toast_type = 'error';
            }
        }
    }
}

// Process CSV File for Test Data (Data Uji)
function processTestCsv($conn, $file)
{
    global $error_message, $success_message, $toast_type;

    $file_handle = fopen($file['tmp_name'], 'r');
    if (!$file_handle) {
        $error_message = "Gagal membuka file CSV.";
        $toast_type = 'error';
    } else {
        fgetcsv($file_handle); // Skip header

        $imported_count = 0;
        $skipped_count = 0;
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_uji')");

        while (($line = fgetcsv($file_handle)) !== FALSE) {
            if (count($line) >= 2) {
                $tweet = trim($line[0]);
                $sentiment = strtolower(trim($line[1])) == 'positive' || strtolower(trim($line[1])) == 'negative' ? strtolower(trim($line[1])) : '';

                if (!empty($tweet) && !empty($sentiment)) {
                    $stmt->bind_param("ss", $tweet, $sentiment);
                    $stmt->execute();
                    $imported_count++;
                } else {
                    $skipped_count++;
                }
            } else {
                $skipped_count++;
            }
        }

        $conn->commit();
        fclose($file_handle);
        $stmt->close();

        if ($imported_count > 0) {
            $success_message = "$imported_count data berhasil diimpor dari CSV.";
            if ($skipped_count > 0) {
                $success_message .= " ($skipped_count data dilewati karena tidak valid)";
            }
            $toast_type = 'success';
        } else {
            $error_message = "Tidak ada data valid yang dapat diimpor dari file CSV.";
            $toast_type = 'error';
        }
    }
}


function processTestExcel($conn, $file, $file_ext)
{
    global $error_message, $success_message, $toast_type;

    try {
        $reader = ($file_ext === 'xlsx') ? new Xlsx() : new Xls();
        $spreadsheet = $reader->load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) <= 1) {
            $error_message = "File Excel tidak berisi data yang cukup.";
            $toast_type = 'error';
        } else {
            array_shift($rows);
            $imported_count = 0;
            $skipped_count = 0;

            $conn->begin_transaction();
            $stmt = $conn->prepare("INSERT INTO dataset (tweet, sentiment, type) VALUES (?, ?, 'data_uji')");

            foreach ($rows as $row) {
                if (count($row) >= 2) {
                    $tweet = isset($row[0]) ? trim($row[0]) : '';
                    $sentiment = isset($row[1]) ? strtolower(trim($row[1])) : '';

                    if (!empty($tweet) && !empty($sentiment) && ($sentiment == 'positive' || $sentiment == 'negative')) {
                        $stmt->bind_param("ss", $tweet, $sentiment);
                        $stmt->execute();
                        $imported_count++;
                    } else {
                        $skipped_count++;
                    }
                } else {
                    $skipped_count++;
                }
            }

            $conn->commit();
            $stmt->close();

            if ($imported_count > 0) {
                $success_message = "$imported_count data berhasil diimpor.";
                if ($skipped_count > 0) {
                    $success_message .= " ($skipped_count dilewati)";
                }
                $toast_type = 'success';
            } else {
                $error_message = "Tidak ada data valid yang berhasil diimpor.";
                $toast_type = 'error';
            }
        }
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Gagal membaca file Excel: " . $e->getMessage();
        $toast_type = 'error';
    }
}

// Delete Test Data (Data Uji)
function deleteTestData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['delete_test_id']) && is_numeric($_POST['delete_test_id'])) {
        $id = $_POST['delete_test_id'];

        $stmt = $conn->prepare("DELETE FROM dataset WHERE id = ? AND type = 'data_uji'");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            $success_message = "Data berhasil dihapus.";
            $toast_type = 'success';
        } else {
            $error_message = "Gagal menghapus data: " . $conn->error;
            $toast_type = 'error';
        }

        $stmt->close();
    }
}

// Delete All Test Data (Data Uji)
function deleteAllTestData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['delete_all_test'])) {
        $stmt = $conn->prepare("DELETE FROM dataset WHERE type = 'data_uji'");

        if ($stmt->execute()) {
            $success_message = "Semua data berhasil dihapus.";
            $toast_type = 'success';
        } else {
            $error_message = "Gagal menghapus data: " . $conn->error;
            $toast_type = 'error';
        }

        $stmt->close();
    }
}

// Edit Test Data (Data Uji)
function editTestData($conn)
{
    global $error_message, $success_message, $toast_type;

    if (isset($_POST['edit_test_data'])) {
        $id = $_POST['edit_test_id'];
        $tweet = trim($_POST['edit_test_tweet']);
        $sentiment = $_POST['edit_test_sentiment'];

        if (empty($tweet) || empty($sentiment)) {
            $error_message = "Tweet dan sentimen harus diisi.";
            $toast_type = 'error';
        } else {
            $stmt = $conn->prepare("UPDATE dataset SET tweet = ?, sentiment = ? WHERE id = ? AND type = 'data_uji'");
            $stmt->bind_param("ssi", $tweet, $sentiment, $id);

            if ($stmt->execute()) {
                $success_message = "Data berhasil diperbarui.";
                $toast_type = 'success';
            } else {
                $error_message = "Gagal memperbarui data: " . $conn->error;
                $toast_type = 'error';
            }

            $stmt->close();
        }
    }
}

// Export Test Data to CSV (Data Uji)
function exportTestCsv($conn)
{
    if (isset($_POST['export_test_csv'])) {
        $stmt = $conn->prepare("SELECT tweet, sentiment FROM dataset WHERE type = 'data_uji' ORDER BY id");
        $stmt->execute();
        $result = $stmt->get_result();

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="data_uji_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Tweet', 'Sentiment']);

        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [$row['tweet'], $row['sentiment']]);
        }

        fclose($output);
        $stmt->close();
        exit;
    }
}

// Fetch Test Data for Pagination (Data Uji)
function fetchTestDataset($conn, $page, $records_per_page)
{
    global $error_message;

    $offset = ($page - 1) * $records_per_page;
    if ($offset < 0) $offset = 0;

    $sql = "SELECT id, tweet, sentiment FROM dataset WHERE type = 'data_uji' ORDER BY id DESC LIMIT ?, ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $offset, $records_per_page);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        $error_message = "Error query: " . $conn->error;
        $dataset = [];
    } else {
        $dataset = $result->fetch_all(MYSQLI_ASSOC);
    }

    return $dataset;
}

// Get Total Pages for Test Data (Data Uji)
function getTestTotalPages($conn, $records_per_page)
{
    $sql_total = "SELECT COUNT(*) AS count FROM dataset WHERE type = 'data_uji'";
    $total_result = $conn->query($sql_total);
    $row = $total_result->fetch_assoc();
    $total_records = $row['count'];
    $total_pages = ceil($total_records / $records_per_page);

    return $total_pages;
}

// end data uji

function getDatasetCounts($conn)
{
    $counts = [
        'data_latih' => 0,
        'data_uji' => 0
    ];

    $sql = "SELECT type, COUNT(*) AS total FROM dataset WHERE type IN ('data_latih', 'data_uji') GROUP BY type";
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $counts[$row['type']] = $row['total'];
        }
    }

    return $counts;
}
