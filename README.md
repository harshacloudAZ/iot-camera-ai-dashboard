# IoT Camera Dashboard with Azure AI Analysis

A comprehensive IoT camera dashboard that captures images from a Raspberry Pi, stores them in Azure Blob Storage, and performs real-time AI analysis using Azure Computer Vision.

## Features

- 📷 **Remote Image Capture** - Send capture commands to Raspberry Pi via Azure IoT Hub
- 🏗️ **Azure Integration** - Seamless integration with Azure IoT Hub and Blob Storage
- 🤖 **Real-time AI Analysis** - Powered by Azure Computer Vision API
- 🖼️ **Image Display** - View captured images with detailed analysis results
- 📊 **Rich Analytics** - Object detection, scene description, color analysis, and more
- 📱 **Responsive Design** - Works on desktop and mobile devices
- ⚡ **Real-time Updates** - Live status monitoring and instant results

## Architecture

```
Raspberry Pi → Azure IoT Hub → Web Dashboard → Azure Computer Vision
     ↓              ↓                ↓              ↓
Image Capture → Command Relay → UI Display → AI Analysis
     ↓
Azure Blob Storage
```

## Prerequisites

### Azure Services
- **Azure IoT Hub** - For device communication
- **Azure Blob Storage** - For image storage
- **Azure Computer Vision** - For AI analysis

### Hardware
- Raspberry Pi with camera module
- Internet connection

## Installation

### 1. Clone the Repository
```bash
git clone https://github.com/yourusername/iot-camera-dashboard.git
cd iot-camera-dashboard
```

### 2. Configure Azure Services

#### Azure IoT Hub Setup
1. Create an Azure IoT Hub resource
2. Register your Raspberry Pi device
3. Get the connection string from IoT Hub → Settings → Shared access policies → service

#### Azure Storage Setup  
1. Create a Storage Account
2. Create a container named `camera-images`
3. Set container access level to "Blob (anonymous read access for blobs only)" for public access

#### Azure Computer Vision Setup
1. Create a Computer Vision resource
2. Get the endpoint URL and API key

### 3. Configuration

Update the configuration in `index.php`:

```php
$config = [
    'iot_hub_connection_string' => 'HostName=your-hub.azure-devices.net;SharedAccessKeyName=service;SharedAccessKey=your-key',
    'device_id' => 'your-device-id',
    'iot_hub_name' => 'your-hub-name',
    'storage_account' => 'your-storage-account',
    'container' => 'camera-images',
    'computer_vision_endpoint' => 'https://your-region.cognitiveservices.azure.com/',
    'computer_vision_key' => 'your-computer-vision-key'
];
```

### 4. Raspberry Pi Setup

Your Raspberry Pi should:
1. Be connected to Azure IoT Hub
2. Listen for `capture_image` method calls
3. Capture images and upload to Azure Blob Storage with format: `analyzed_capture_YYYYMMDD_HHMMSS_XXXX.jpg`

## Usage

### Web Dashboard
1. Deploy `index.php` to your web server (Apache/Nginx with PHP support)
2. Access the dashboard in your browser
3. Click "📷 Capture & Analyze Image" to:
   - Send capture command to Raspberry Pi
   - Wait for image upload to Azure Storage
   - Perform AI analysis with Azure Computer Vision
   - Display results with detailed insights

### API Endpoints

The dashboard provides REST API endpoints:

- `GET /?api=status` - Get system status
- `POST /?api=capture` - Trigger image capture
- `GET /?api=real_analysis&filename=image.jpg` - Analyze specific image

## Analysis Features

### 🤖 AI Analysis Results
- **Scene Description** - Natural language description of the image
- **Object Detection** - Identified objects with confidence scores
- **Image Tags** - Relevant tags categorized by confidence level
- **Color Analysis** - Dominant colors and color scheme
- **Scene Categories** - Scene classification
- **Face Detection** - Number of people detected
- **Brand Recognition** - Identified brands in the image

### 📊 Confidence Scoring
All analysis results include confidence percentages to help assess accuracy.

## File Structure

```
iot-camera-dashboard/
├── index.php              # Main dashboard application
├── README.md              # This file
├── LICENSE                # License file
├── .gitignore            # Git ignore rules
├── screenshots/          # Demo screenshots
│   ├── dashboard.png
│   ├── analysis.png
│   └── mobile.png
└── docs/                 # Additional documentation
    ├── SETUP.md          # Detailed setup guide
    ├── API.md            # API documentation
    └── TROUBLESHOOTING.md # Common issues and solutions
```

## Screenshots

### Dashboard Overview
![Dashboard](screenshots/dashboard.png)

### AI Analysis Results
![Analysis](screenshots/analysis.png)

### Mobile View
![Mobile](screenshots/mobile.png)

## Troubleshooting

### Common Issues

#### Image Not Displaying
- Check if Azure Blob Storage container is public
- Verify the image filename format matches Pi output
- Ensure sufficient delay for image upload

#### Analysis Fails
- Verify Azure Computer Vision credentials
- Check if image URL is accessible
- Ensure image format is supported (JPG, PNG, GIF, BMP)

#### Capture Command Fails
- Verify IoT Hub connection string
- Check if Raspberry Pi is online and connected
- Ensure device ID matches registered device

## Development

### Debug Mode
Enable debug information by changing CSS:
```css
.debug-info {
    display: block; /* Change from 'none' to 'block' */
}
```

### Adding Features
The codebase is modular and easy to extend:
- Add new analysis features in `formatRealAnalysis()`
- Extend UI components in the HTML/CSS sections
- Add new API endpoints in the switch statement

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- Azure IoT Hub for reliable device communication
- Azure Computer Vision for powerful AI analysis
- Bootstrap and modern CSS for responsive design
- Open source community for inspiration and support

