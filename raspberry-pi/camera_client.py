#!/usr/bin/env python3
"""
IoT Camera Client for Raspberry Pi
Captures images on command and uploads to Azure Blob Storage
"""

import asyncio
import json
import os
import sys
import logging
from datetime import datetime
from azure.iot.device.aio import IoTHubDeviceClient
from azure.iot.device import MethodResponse, Message
from azure.storage.blob import BlobServiceClient
import subprocess
import uuid
from pathlib import Path

# Configuration
DEVICE_CONNECTION_STRING = os.getenv('DEVICE_CONNECTION_STRING', '')
STORAGE_CONNECTION_STRING = os.getenv('STORAGE_CONNECTION_STRING', '')
CONTAINER_NAME = "camera-images"
TEMP_DIR = "/tmp"
IMAGE_WIDTH = 1024
IMAGE_HEIGHT = 768
IMAGE_QUALITY = 85

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('/var/log/camera-client.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)


class CameraDevice:
    def __init__(self):
        """Initialize the camera device client"""
        if not DEVICE_CONNECTION_STRING:
            raise ValueError(
                "DEVICE_CONNECTION_STRING environment variable not set")
        if not STORAGE_CONNECTION_STRING:
            raise ValueError(
                "STORAGE_CONNECTION_STRING environment variable not set")

        self.client = IoTHubDeviceClient.create_from_connection_string(
            DEVICE_CONNECTION_STRING)
        self.blob_service_client = BlobServiceClient.from_connection_string(
            STORAGE_CONNECTION_STRING)
        self.is_connected = False
        logger.info("Camera device initialized")

    def check_camera(self):
        """Check if camera is available"""
        try:
            # Test camera with a quick capture
            test_result = subprocess.run([
                "raspistill", "-t", "1", "-o", "/dev/null"
            ], capture_output=True, timeout=10)

            if test_result.returncode == 0:
                logger.info("Camera check passed")
                return True
            else:
                logger.error(
                    f"Camera check failed: {test_result.stderr.decode()}")
                return False

        except subprocess.TimeoutExpired:
            logger.error("Camera check timed out")
            return False
        except Exception as e:
            logger.error(f"Camera check error: {str(e)}")
            return False

    async def capture_image(self, payload=None):
        """Capture image and upload to Azure Storage"""
        try:
            logger.info(f"Starting image capture with payload: {payload}")

            # Check camera availability
            if not self.check_camera():
                raise Exception("Camera not available")

            # Generate unique filename
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            unique_id = str(uuid.uuid4())[:8]
            filename = f"analyzed_capture_{timestamp}_{unique_id}.jpg"
            temp_path = os.path.join(TEMP_DIR, filename)

            logger.info(f"Capturing image: {filename}")

            # Capture image with raspistill
            capture_cmd = [
                "raspistill",
                "-o", temp_path,
                "-w", str(IMAGE_WIDTH),
                "-h", str(IMAGE_HEIGHT),
                "-q", str(IMAGE_QUALITY),
                "-t", "2000",  # 2 second delay
                "--nopreview"
            ]

            # Add rotation if specified in payload
            if payload and 'rotation' in payload:
                capture_cmd.extend(["-rot", str(payload['rotation'])])

            # Add effects if specified
            if payload and 'effect' in payload:
                capture_cmd.extend(["-ifx", payload['effect']])

            capture_result = subprocess.run(
                capture_cmd,
                capture_output=True,
                timeout=30
            )

            if capture_result.returncode != 0:
                error_msg = capture_result.stderr.decode(
                ) if capture_result.stderr else "Unknown capture error"
                raise Exception(f"Image capture failed: {error_msg}")

            # Verify file was created
            if not os.path.exists(temp_path):
                raise Exception("Image file was not created")

            file_size = os.path.getsize(temp_path)
            logger.info(f"Image captured successfully: {file_size} bytes")

            # Upload to Azure Storage
            logger.info(f"Uploading to Azure Storage: {filename}")
            blob_client = self.blob_service_client.get_blob_client(
                container=CONTAINER_NAME,
                blob=filename
            )

            with open(temp_path, "rb") as data:
                blob_client.upload_blob(data, overwrite=True)

            # Get blob URL
            blob_url = f"https://{self.blob_service_client.account_name}.blob.core.windows.net/{CONTAINER_NAME}/{filename}"

            # Clean up local file
            os.remove(temp_path)
            logger.info(f"Image uploaded successfully: {blob_url}")

            # Send telemetry
            await self.send_telemetry({
                "event": "image_captured",
                "filename": filename,
                "size_bytes": file_size,
                "timestamp": datetime.now().isoformat()
            })

            return {
                "status": "success",
                "filename": filename,
                "blob_url": blob_url,
                "size_bytes": file_size,
                "timestamp": datetime.now().isoformat(),
                "message": "Image captured and uploaded successfully"
            }

        except subprocess.TimeoutExpired:
            logger.error("Image capture timed out")
            return {
                "status": "error",
                "message": "Image capture timed out"
            }
        except Exception as e:
            logger.error(f"Capture error: {str(e)}")
            return {
                "status": "error",
                "message": str(e)
            }

    async def get_device_info(self, payload=None):
        """Get device information"""
        try:
            # Get system info
            hostname = subprocess.check_output(["hostname"]).decode().strip()
            uptime = subprocess.check_output(["uptime", "-p"]).decode().strip()

            # Get disk space
            disk_usage = subprocess.check_output(
                ["df", "-h", "/"]).decode().split('\n')[1].split()

            # Get temperature
            try:
                temp_output = subprocess.check_output(
                    ["/opt/vc/bin/vcgencmd", "measure_temp"]).decode()
                temperature = temp_output.strip().replace("temp=", "").replace("'C", "°C")
            except:
                temperature = "Unknown"

            return {
                "status": "success",
                "device_info": {
                    "hostname": hostname,
                    "uptime": uptime,
                    "disk_used": disk_usage[2],
                    "disk_available": disk_usage[3],
                    "disk_usage_percent": disk_usage[4],
                    "temperature": temperature,
                    "timestamp": datetime.now().isoformat()
                }
            }

        except Exception as e:
            logger.error(f"Device info error: {str(e)}")
            return {
                "status": "error",
                "message": str(e)
            }

    async def test_storage(self, payload=None):
        """Test Azure Storage connectivity"""
        try:
            # Test storage connection
            container_client = self.blob_service_client.get_container_client(
                CONTAINER_NAME)
            container_properties = container_client.get_container_properties()

            return {
                "status": "success",
                "storage_info": {
                    "container": CONTAINER_NAME,
                    "last_modified": container_properties.last_modified.isoformat(),
                    "account_name": self.blob_service_client.account_name,
                    "test_time": datetime.now().isoformat()
                }
            }

        except Exception as e:
            logger.error(f"Storage test error: {str(e)}")
            return {
                "status": "error",
                "message": str(e)
            }

    async def method_handler(self, method_request):
        """Handle IoT Hub method calls"""
        logger.info(f"Received method: {method_request.name}")

        try:
            if method_request.name == "capture_image":
                result = await self.capture_image(method_request.payload)
                status_code = 200 if result["status"] == "success" else 500

            elif method_request.name == "get_device_info":
                result = await self.get_device_info(method_request.payload)
                status_code = 200 if result["status"] == "success" else 500

            elif method_request.name == "test_storage":
                result = await self.test_storage(method_request.payload)
                status_code = 200 if result["status"] == "success" else 500

            elif method_request.name == "ping":
                result = {
                    "status": "success",
                    "message": "pong",
                    "timestamp": datetime.now().isoformat()
                }
                status_code = 200

            else:
                result = {
                    "status": "error",
                    "message": f"Method '{method_request.name}' not supported"
                }
                status_code = 404

        except Exception as e:
            logger.error(f"Method handler error: {str(e)}")
            result = {
                "status": "error",
                "message": f"Internal error: {str(e)}"
            }
            status_code = 500

        # Send response
        response = MethodResponse.create_from_method_request(
            method_request, status_code, result
        )

        await self.client.send_method_response(response)
        logger.info(f"Method response sent: {result['status']}")

    async def send_telemetry(self, data):
        """Send telemetry data to IoT Hub"""
        try:
            message = Message(json.dumps(data))
            message.content_encoding = "utf-8"
            message.content_type = "application/json"
            await self.client.send_message(message)
            logger.debug(f"Telemetry sent: {data}")
        except Exception as e:
            logger.error(f"Failed to send telemetry: {str(e)}")

    async def connection_handler(self):
        """Handle connection events"""
        def on_connection_state_change():
            logger.info(f"Connection state changed: {self.client.connected}")
            self.is_connected = self.client.connected

        def on_disconnect():
            logger.warning("Disconnected from IoT Hub")
            self.is_connected = False

        # Set event handlers
        self.client.on_connection_state_change = on_connection_state_change

    async def run(self):
        """Main run loop"""
        try:
            logger.info("Starting camera device client...")

            # Setup connection handlers
            await self.connection_handler()

            # Connect to IoT Hub
            await self.client.connect()
            self.is_connected = True
            logger.info("Connected to IoT Hub")

            # Set method handler
            self.client.on_method_request_received = self.method_handler

            # Send startup telemetry
            await self.send_telemetry({
                "event": "device_started",
                "timestamp": datetime.now().isoformat(),
                "camera_available": self.check_camera()
            })

            logger.info("Camera device ready. Waiting for commands...")

            # Keep the client running
            while True:
                await asyncio.sleep(30)  # Send heartbeat every 30 seconds

                if self.is_connected:
                    await self.send_telemetry({
                        "event": "heartbeat",
                        "timestamp": datetime.now().isoformat()
                    })

        except KeyboardInterrupt:
            logger.info("Shutting down...")
        except Exception as e:
            logger.error(f"Runtime error: {str(e)}")
            raise
        finally:
            if self.is_connected:
                await self.send_telemetry({
                    "event": "device_stopped",
                    "timestamp": datetime.now().isoformat()
                })
                await self.client.disconnect()
                logger.info("Disconnected from IoT Hub")


def main():
    """Main entry point"""
    try:
        # Check if running on Raspberry Pi
        if not os.path.exists('/opt/vc/bin/vcgencmd'):
            logger.warning(
                "Not running on Raspberry Pi - some features may not work")

        # Create and run device
        device = CameraDevice()
        asyncio.run(device.run())

    except Exception as e:
        logger.error(f"Failed to start camera device: {str(e)}")
        sys.exit(1)


if __name__ == "__main__":
    main()
