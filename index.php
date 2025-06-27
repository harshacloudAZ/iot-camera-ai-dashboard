<?php
/**
 * Clean IoT Camera Dashboard - Image Capture & Real AI Analysis
 * Focused on core functionality: capture, display, analyze with Azure Computer Vision
 */

// Clean error handling
ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();

// Configuration with real Azure Computer Vision
$config = [
    'iot_hub_connection_string' => $_ENV['IOT_HUB_CONNECTION_STRING'] ?? 'HostName=harsha.azure-devices.net;SharedAccessKeyName=service;SharedAccessKey=FtkcXJwDMmZKDb0vLWFC/qF8BjEVW/MjNAIoTImsjv4=',
    'device_id' => 'raspberrypi',
    'iot_hub_name' => 'harsha',
    'storage_account' => 'picamera',
    'container' => 'camera-images',
    'computer_vision_endpoint' => 'https://harshacamera.cognitiveservices.azure.com/',
    'computer_vision_key' => 'oyLdvMtVEU141CT4a3yTAOEE8kqIrARKJLee3ASxxZQdELErbcpLJQQJ99BFACNns7RXJ3w3AAAFACOGd5Pc'
];

// Handle API requests
if (isset($_GET['api'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        switch ($_GET['api']) {
            case 'capture':
                $result = handleCapture($config);
                break;
            case 'real_analysis':
                $filename = $_GET['filename'] ?? '';
                if (empty($filename)) {
                    $result = ['status' => 'error', 'message' => 'No filename provided'];
                } else {
                    // Handle special case for getting latest file
                    if ($filename === 'LATEST') {
                        $filename = getLatestBlobFromStorage($config);
                        if (!$filename) {
                            $result = ['status' => 'error', 'message' => 'Could not find latest captured image'];
                            break;
                        }
                    }
                    
                    $imageUrl = "https://{$config['storage_account']}.blob.core.windows.net/{$config['container']}/{$filename}";
                    $result = getRealAnalysis($imageUrl, $config);
                }
                break;
            case 'status':
                $result = getStatus($config);
                break;
            default:
                $result = ['status' => 'error', 'message' => 'Unknown API endpoint'];
        }
        
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    
    exit;
}

ob_end_clean();

// Capture handler - get actual filename from Pi response
function handleCapture($config) {
    try {
        $iot_details = parseConnectionString($config['iot_hub_connection_string']);
        
        if (!$iot_details) {
            throw new Exception('Invalid IoT Hub connection string');
        }
        
        // Send capture command and get response
        $result = sendCaptureCommand($iot_details, $config['device_id']);
        
        // Log the full Pi response to see what we get
        error_log("Full Pi Response: " . print_r($result, true));
        
        // Try to extract filename from different possible locations
        $actualFilename = null;
        
        // Check various possible locations for the filename
        if (isset($result['payload']['filename'])) {
            $actualFilename = $result['payload']['filename'];
        } elseif (isset($result['payload']['image_name'])) {
            $actualFilename = $result['payload']['image_name'];
        } elseif (isset($result['payload']['blob_name'])) {
            $actualFilename = $result['payload']['blob_name'];
        } elseif (isset($result['result']['filename'])) {
            $actualFilename = $result['result']['filename'];
        } elseif (isset($result['payload']['image_path'])) {
            // Extract filename from path
            $actualFilename = basename($result['payload']['image_path']);
        }
        
        // If no filename found, we'll need to get the latest file from storage
        if (!$actualFilename) {
            error_log("No filename found in Pi response, will use LATEST approach");
            $actualFilename = 'LATEST'; // Special flag to get latest file
        } else {
            error_log("Found filename in Pi response: " . $actualFilename);
        }
        
        return [
            'status' => 'success',
            'message' => 'Capture command sent to ' . $config['device_id'],
            'hub' => $config['iot_hub_name'],
            'device' => $config['device_id'],
            'timestamp' => date('c'),
            'image_filename' => $actualFilename,
            'pi_response' => $result
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Failed to send capture command',
            'error' => $e->getMessage()
        ];
    }
}

// Parse connection string
function parseConnectionString($connectionString) {
    $parts = [];
    foreach (explode(';', $connectionString) as $part) {
        if (strpos($part, '=') !== false) {
            list($key, $value) = explode('=', $part, 2);
            $parts[strtolower($key)] = $value;
        }
    }
    
    return [
        'hostname' => $parts['hostname'] ?? null,
        'shared_access_key_name' => $parts['sharedaccesskeyname'] ?? null,
        'shared_access_key' => $parts['sharedaccesskey'] ?? null
    ];
}

// Send capture command to device
function sendCaptureCommand($iot_details, $deviceId) {
    $resourceUri = "https://{$iot_details['hostname']}/twins/{$deviceId}/methods";
    $expiry = time() + 3600;
    
    $stringToSign = urlencode($resourceUri) . "\n" . $expiry;
    $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($iot_details['shared_access_key']), true));
    $sasToken = "SharedAccessSignature sr=" . urlencode($resourceUri) . "&sig=" . urlencode($signature) . "&se=" . $expiry . "&skn=" . $iot_details['shared_access_key_name'];
    
    $postData = json_encode([
        'methodName' => 'capture_image',
        'responseTimeoutInSeconds' => 30,
        'payload' => ['timestamp' => date('c')]
    ]);
    
    $headers = [
        'Authorization: ' . $sasToken,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postData)
    ];
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $postData,
            'timeout' => 30
        ]
    ]);
    
    $url = "https://{$iot_details['hostname']}/twins/{$deviceId}/methods?api-version=2021-04-12";
    $response = file_get_contents($url, false, $context);
    
    if ($response === false) {
        throw new Exception('Failed to send command to device');
    }
    
    return json_decode($response, true);
}

// Get latest blob from storage when filename is unknown
function getLatestBlobFromStorage($config) {
    try {
        error_log("Attempting to get latest blob from storage...");
        
        // List blobs in container (this works for public containers)
        $containerUrl = "https://{$config['storage_account']}.blob.core.windows.net/{$config['container']}?restype=container&comp=list";
        
        error_log("Container URL: " . $containerUrl);
        
        $response = @file_get_contents($containerUrl);
        
        if (!$response) {
            error_log("Failed to get container listing");
            return null;
        }
        
        error_log("Container response received, parsing XML...");
        
        // Parse XML response to get blob names
        $xml = simplexml_load_string($response);
        
        if (!$xml) {
            error_log("Failed to parse XML response");
            return null;
        }
        
        $blobs = [];
        
        foreach ($xml->Blobs->Blob as $blob) {
            $name = (string)$blob->Name;
            $lastModified = (string)$blob->Properties->{'Last-Modified'};
            
            // Look for analyzed_capture files
            if (strpos($name, 'analyzed_capture_') === 0) {
                $blobs[] = [
                    'name' => $name,
                    'modified' => strtotime($lastModified)
                ];
                error_log("Found blob: " . $name . " modified: " . $lastModified);
            }
        }
        
        if (empty($blobs)) {
            error_log("No analyzed_capture blobs found");
            return null;
        }
        
        // Sort by modification time, get latest
        usort($blobs, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        $latestBlob = $blobs[0]['name'];
        error_log("Latest blob: " . $latestBlob);
        
        return $latestBlob;
        
    } catch (Exception $e) {
        error_log("Error getting latest blob: " . $e->getMessage());
        return null;
    }
}

// Real Azure Computer Vision Analysis with debugging
function getRealAnalysis($imageUrl, $config) {
    try {
        // Debug: Log what we're trying to analyze
        error_log("=== DEBUGGING COMPUTER VISION ===");
        error_log("Image URL: " . $imageUrl);
        
        // Test if image is accessible
        $imageHeaders = @get_headers($imageUrl);
        error_log("Image headers: " . print_r($imageHeaders, true));
        
        if (!$imageHeaders || !preg_match('/200/', $imageHeaders[0])) {
            throw new Exception("Image not accessible at: " . $imageUrl . " | Response: " . ($imageHeaders[0] ?? 'No response'));
        }
        
        $endpoint = rtrim($config['computer_vision_endpoint'], '/');
        $apiUrl = $endpoint . '/vision/v3.2/analyze?visualFeatures=Description,Objects,Tags,Color,Categories,Faces,Brands';
        
        error_log("Computer Vision API URL: " . $apiUrl);
        
        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $config['computer_vision_key'],
            'Content-Type: application/json'
        ];
        
        $postData = json_encode(['url' => $imageUrl]);
        error_log("POST data: " . $postData);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $postData,
                'timeout' => 30
            ]
        ]);
        
        $response = file_get_contents($apiUrl, false, $context);
        error_log("Azure CV Response: " . $response);
        
        if ($response === false) {
            $error = error_get_last();
            throw new Exception('Failed to call Azure Computer Vision API. Error: ' . ($error['message'] ?? 'Unknown error'));
        }
        
        $analysisResult = json_decode($response, true);
        error_log("Parsed response: " . print_r($analysisResult, true));
        
        if (isset($analysisResult['error'])) {
            throw new Exception('Azure Computer Vision Error: ' . $analysisResult['error']['message'] . ' (Code: ' . ($analysisResult['error']['code'] ?? 'Unknown') . ')');
        }
        
        return [
            'status' => 'success',
            'data' => [
                'image_url' => $imageUrl,
                'timestamp' => date('c'),
                'analysis' => formatRealAnalysis($analysisResult)
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Analysis Exception: " . $e->getMessage());
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

// Format Azure Computer Vision response to match UI
function formatRealAnalysis($result) {
    $description = $result['description']['captions'][0] ?? ['text' => 'No description available', 'confidence' => 0];
    
    return [
        'primary_description' => $description['text'],
        'confidence' => $description['confidence'] * 100,
        'detailed_description' => $description['text'],
        'objects' => array_map(function($obj) {
            return [
                'name' => $obj['object'],
                'confidence' => $obj['confidence'] * 100
            ];
        }, $result['objects'] ?? []),
        'tags' => [
            'high' => array_column(array_filter($result['tags'] ?? [], function($tag) {
                return $tag['confidence'] > 0.8;
            }), 'name'),
            'medium' => array_column(array_filter($result['tags'] ?? [], function($tag) {
                return $tag['confidence'] >= 0.5 && $tag['confidence'] <= 0.8;
            }), 'name')
        ],
        'colors' => $result['color']['dominantColors'] ?? [],
        'color_scheme' => ($result['color']['isBwImg'] ?? false) ? 'black and white' : 'color',
        'scene_categories' => array_column($result['categories'] ?? [], 'name'),
        'people_count' => count($result['faces'] ?? []),
        'brands' => array_column($result['brands'] ?? [], 'name')
    ];
}

// Simple status check
function getStatus($config) {
    return [
        'status' => 'success',
        'device_id' => $config['device_id'],
        'iot_hub' => $config['iot_hub_name'],
        'storage_account' => $config['storage_account'],
        'container' => $config['container'],
        'computer_vision' => 'enabled',
        'server_time' => date('c')
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IoT Camera Dashboard - Real AI Analysis</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .main-content {
            padding: 40px;
        }

        .capture-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
        }

        .capture-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 1.2rem;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .capture-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .capture-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .status-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .status-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }

        .status-card h3 {
            color: #495057;
            margin-bottom: 10px;
            font-size: 1rem;
        }

        .status-value {
            font-size: 1.3rem;
            font-weight: bold;
            color: #007bff;
        }

        .image-analysis-section {
            display: none;
            margin-top: 30px;
        }

        .image-grid {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 30px;
        }

        .image-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .captured-image {
            width: 100%;
            max-height: 350px;
            object-fit: contain;
            border-radius: 10px;
            cursor: pointer;
        }

        .image-info {
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 0.9rem;
            color: #6c757d;
        }

        .analysis-panel {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .analysis-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
        }

        .analysis-card h4 {
            color: #495057;
            margin-bottom: 10px;
            font-size: 1rem;
        }

        .primary-description {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-left-color: #2196f3;
        }

        .primary-description .content {
            color: #1565c0;
            font-weight: 500;
            font-size: 1.05rem;
        }

        .confidence-bar {
            background: #e9ecef;
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }

        .confidence-fill {
            height: 100%;
            background: linear-gradient(90deg, #dc3545 0%, #ffc107 50%, #28a745 100%);
            transition: width 0.8s ease;
        }

        .objects-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .object-tag {
            background: #d4edda;
            border: 1px solid #28a745;
            color: #155724;
            border-radius: 12px;
            padding: 4px 10px;
            font-size: 0.8rem;
        }

        .tags-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .tag {
            background: #007bff;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
        }

        .tag.high {
            background: #28a745;
        }

        .tag.medium {
            background: #ffc107;
            color: #000;
        }

        .colors-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .color-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .loading {
            display: none;
            text-align: center;
            padding: 30px;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .message {
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            display: none;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .placeholder {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }

        .placeholder .icon {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
            font-size: 0.85rem;
            color: #495057;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }

        @media (max-width: 768px) {
            .image-grid {
                grid-template-columns: 1fr;
            }
            .main-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📷 IoT Camera Dashboard</h1>
            <p>Real-time Image Capture & Azure AI Analysis</p>
        </div>

        <div class="main-content">
            <!-- Capture Section -->
            <div class="capture-section">
                <h2>📸 Image Capture</h2>
                <p>Capture a photo from your Raspberry Pi and get real Azure AI analysis</p>
                <br>
                <button id="captureBtn" class="capture-btn" onclick="captureImage()">
                    📷 Capture & Analyze Image
                </button>
            </div>

            <!-- Status Bar -->
            <div class="status-bar">
                <div class="status-card">
                    <h3>Device</h3>
                    <div class="status-value">🟢 raspberrypi</div>
                </div>
                <div class="status-card">
                    <h3>IoT Hub</h3>
                    <div class="status-value">harsha</div>
                </div>
                <div class="status-card">
                    <h3>Storage</h3>
                    <div class="status-value">picamera</div>
                </div>
                <div class="status-card">
                    <h3>AI Analysis</h3>
                    <div class="status-value">🧠 Azure CV</div>
                </div>
                <div class="status-card">
                    <h3>Last Capture</h3>
                    <div class="status-value" id="lastCapture">Never</div>
                </div>
            </div>

            <!-- Loading -->
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Capturing image and performing real AI analysis...</p>
            </div>

            <!-- Messages -->
            <div class="message success" id="successMessage"></div>
            <div class="message error" id="errorMessage"></div>
            
            <!-- Debug Info -->
            <div class="debug-info" id="debugInfo">Debug information will appear here...</div>

            <!-- Image Analysis Section -->
            <div class="image-analysis-section" id="imageAnalysisSection">
                <h2>🖼️ Latest Captured Image & Real AI Analysis</h2>
                
                <div class="image-grid">
                    <!-- Image Display -->
                    <div class="image-container">
                        <img id="capturedImage" class="captured-image" alt="Captured Image" onclick="openFullscreen()">
                        <div class="image-info" id="imageInfo">
                            <strong>Image Details:</strong><br>
                            Captured: <span id="captureTime">-</span><br>
                            Analysis: <span id="analysisTime">-</span><br>
                            Filename: <span id="imageFilename">-</span>
                        </div>
                    </div>

                    <!-- Analysis Panel -->
                    <div class="analysis-panel">
                        <h3>🤖 Azure AI Analysis Results</h3>
                        
                        <!-- Primary Description -->
                        <div class="analysis-card primary-description">
                            <h4>📝 Scene Description</h4>
                            <div class="content" id="primaryDescription">
                                Real analysis results will appear here...
                            </div>
                            <div class="confidence-bar">
                                <div class="confidence-fill" id="confidenceBar"></div>
                            </div>
                            <small style="margin-top: 5px; display: block;">
                                Confidence: <span id="confidenceText">0%</span>
                            </small>
                        </div>

                        <!-- Objects -->
                        <div class="analysis-card">
                            <h4>🎯 Objects Detected <span id="objectCount">(0)</span></h4>
                            <div class="objects-list" id="objectsList">
                                <!-- Objects will be populated here -->
                            </div>
                        </div>

                        <!-- Tags -->
                        <div class="analysis-card">
                            <h4>🏷️ Image Tags</h4>
                            <div class="tags-list" id="tagsList">
                                <!-- Tags will be populated here -->
                            </div>
                        </div>

                        <!-- Colors -->
                        <div class="analysis-card">
                            <h4>🎨 Color Analysis</h4>
                            <div>Scheme: <strong id="colorScheme">Unknown</strong></div>
                            <div class="colors-row" id="colorsRow">
                                <!-- Color dots will be populated here -->
                            </div>
                        </div>

                        <!-- Scene Categories -->
                        <div class="analysis-card">
                            <h4>📂 Scene Categories</h4>
                            <div id="sceneCategories">Categories will appear here...</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Placeholder -->
            <div class="placeholder" id="placeholder">
                <div class="icon">📷</div>
                <h3>No Image Captured Yet</h3>
                <p>Click "Capture & Analyze Image" to take a photo and see real Azure AI analysis results.</p>
            </div>
        </div>
    </div>

    <script>
        let isCapturing = false;

        async function captureImage() {
            if (isCapturing) return;
            
            isCapturing = true;
            const captureBtn = document.getElementById('captureBtn');
            const loading = document.getElementById('loading');
            
            // Show loading
            captureBtn.disabled = true;
            loading.style.display = 'block';
            hideMessages();
            
            try {
                // Send capture command
                const response = await fetch('?api=capture', { method: 'POST' });
                const result = await response.json();
                
                if (result.status === 'success') {
                    showSuccess('📸 Image captured! Performing real Azure AI analysis...');
                    updateLastCapture();
                    
                    // Show debug info
                    showDebugInfo('Capture Response', result);
                    
                    // Wait for image upload then perform real analysis
                    setTimeout(async () => {
                        await showRealAnalysisResults(result);
                    }, 10000); // Increased delay for upload
                    
                } else {
                    throw new Error(result.message || 'Capture failed');
                }
                
            } catch (error) {
                showError('❌ Failed to capture image: ' + error.message);
            } finally {
                setTimeout(() => {
                    isCapturing = false;
                    captureBtn.disabled = false;
                    loading.style.display = 'none';
                }, 15000);
            }
        }

        async function showRealAnalysisResults(captureResult) {
            try {
                // Get the actual image filename from Pi response
                const imageFilename = captureResult.image_filename || 'LATEST';
                
                showDebugInfo('Using filename for analysis', imageFilename);
                
                // Call real analysis with filename parameter
                const response = await fetch(`?api=real_analysis&filename=${encodeURIComponent(imageFilename)}`);
                const result = await response.json();
                
                showDebugInfo('Analysis API Response', result);
                
                if (result.status === 'success') {
                    displayImageAndAnalysis(result.data, imageFilename);
                    showSuccess('🎉 Real Azure AI analysis completed!');
                } else {
                    throw new Error(result.message || 'Analysis failed');
                }
                
            } catch (error) {
                showError('❌ Real analysis failed: ' + error.message);
                console.error('Analysis error:', error);
            }
        }

        function displayImageAndAnalysis(data, filename) {
            // Hide placeholder and show analysis section
            document.getElementById('placeholder').style.display = 'none';
            document.getElementById('imageAnalysisSection').style.display = 'block';
            
            // Set the real captured image URL
            const image = document.getElementById('capturedImage');
            image.onerror = function() {
                // If image fails to load, show placeholder
                this.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjhmOWZhIiBzdHJva2U9IiNkZWUyZTYiLz48dGV4dCB4PSI1MCUiIHk9IjQwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0IiBmaWxsPSIjNmM3NTdkIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5JbWFnZSBDYXB0dXJlZDwvdGV4dD48dGV4dCB4PSI1MCUiIHk9IjYwJSIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjEyIiBmaWxsPSIjOTk5IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5VcGxvYWRpbmcgdG8gc3RvcmFnZS4uLjwvdGV4dD48L3N2Zz4=';
                showError('❌ Image failed to load. Still uploading to storage...');
            };
            image.src = data.image_url; // Use real image URL
            
            // Update image info
            document.getElementById('captureTime').textContent = new Date(data.timestamp).toLocaleString();
            document.getElementById('analysisTime').textContent = new Date().toLocaleString();
            document.getElementById('imageFilename').textContent = filename;
            
            // Display real analysis results
            const analysis = data.analysis;
            
            // Primary description
            document.getElementById('primaryDescription').textContent = analysis.primary_description;
            document.getElementById('confidenceBar').style.width = analysis.confidence + '%';
            document.getElementById('confidenceText').textContent = analysis.confidence.toFixed(1) + '%';
            
            // Objects
            const objectsList = document.getElementById('objectsList');
            objectsList.innerHTML = '';
            document.getElementById('objectCount').textContent = `(${analysis.objects.length})`;
            
            if (analysis.objects.length === 0) {
                objectsList.innerHTML = '<span style="color: #6c757d; font-style: italic;">No objects detected</span>';
            } else {
                analysis.objects.forEach(obj => {
                    const tag = document.createElement('span');
                    tag.className = 'object-tag';
                    tag.textContent = `${obj.name} (${obj.confidence.toFixed(1)}%)`;
                    objectsList.appendChild(tag);
                });
            }
            
            // Tags
            const tagsList = document.getElementById('tagsList');
            tagsList.innerHTML = '';
            
            if (analysis.tags.high.length === 0 && analysis.tags.medium.length === 0) {
                tagsList.innerHTML = '<span style="color: #6c757d; font-style: italic;">No tags detected</span>';
            } else {
                analysis.tags.high.forEach(tagName => {
                    const tag = document.createElement('span');
                    tag.className = 'tag high';
                    tag.textContent = tagName;
                    tagsList.appendChild(tag);
                });
                
                analysis.tags.medium.forEach(tagName => {
                    const tag = document.createElement('span');
                    tag.className = 'tag medium';
                    tag.textContent = tagName;
                    tagsList.appendChild(tag);
                });
            }
            
            // Colors
            document.getElementById('colorScheme').textContent = analysis.color_scheme;
            const colorsRow = document.getElementById('colorsRow');
            colorsRow.innerHTML = '<span style="margin-right: 10px;">Colors:</span>';
            
            const colorMap = {
                'Brown': '#8B4513', 'White': '#FFFFFF', 'Black': '#333333',
                'Red': '#DC3545', 'Blue': '#007BFF', 'Green': '#28A745',
                'Gray': '#6C757D', 'Silver': '#C0C0C0', 'Yellow': '#FFC107',
                'Orange': '#FD7E14', 'Purple': '#6F42C1', 'Pink': '#E83E8C'
            };
            
            if (analysis.colors.length === 0) {
                colorsRow.innerHTML += '<span style="color: #6c757d; font-style: italic;">No dominant colors detected</span>';
            } else {
                analysis.colors.forEach(color => {
                    const dot = document.createElement('div');
                    dot.className = 'color-dot';
                    dot.style.backgroundColor = colorMap[color] || '#999';
                    dot.title = color;
                    colorsRow.appendChild(dot);
                });
            }
            
            // Scene categories
            document.getElementById('sceneCategories').textContent = analysis.scene_categories.length > 0 
                ? analysis.scene_categories.join(', ')
                : 'No categories detected';
        }

        function openFullscreen() {
            const image = document.getElementById('capturedImage');
            if (image.src && !image.src.includes('data:image/svg')) {
                window.open(image.src, '_blank');
            }
        }

        function updateLastCapture() {
            document.getElementById('lastCapture').textContent = new Date().toLocaleTimeString();
        }

        function showSuccess(message) {
            const div = document.getElementById('successMessage');
            div.textContent = message;
            div.style.display = 'block';
            setTimeout(() => div.style.display = 'none', 5000);
        }

        function showError(message) {
            const div = document.getElementById('errorMessage');
            div.textContent = message;
            div.style.display = 'block';
            setTimeout(() => div.style.display = 'none', 8000);
        }

        function hideMessages() {
            document.getElementById('successMessage').style.display = 'none';
            document.getElementById('errorMessage').style.display = 'none';
        }

        function showDebugInfo(title, data) {
            const debugDiv = document.getElementById('debugInfo');
            const timestamp = new Date().toLocaleTimeString();
            const debugText = `[${timestamp}] ${title}:\n${JSON.stringify(data, null, 2)}\n\n`;
            
            debugDiv.innerHTML += debugText;
            
            // Auto-scroll to bottom
            debugDiv.scrollTop = debugDiv.scrollHeight;
        }

        // Clear debug info on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('debugInfo').innerHTML = 'Debug information will appear here...\n';
        });
    </script>
</body>
</html>