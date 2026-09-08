from pathlib import Path
import subprocess
from zipfile import ZipFile
root = Path(__file__).resolve().parents[1]
subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True, capture_output=True)
archive = root/'dist/zoer-connect-0.1.0.zip'
first = archive.read_bytes()
subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True, capture_output=True)
assert first == archive.read_bytes(), 'Build must be reproducible'
with ZipFile(archive) as z:
    assert set(z.namelist()) == {'zoer-connect/zoer-connect.php','zoer-connect/includes/Plugin.php','zoer-connect/includes/FilePublication.php','zoer-connect/includes/TableStage.php','zoer-connect/includes/SettingsPreservation.php','zoer-connect/includes/RecoveryCoordinator.php','zoer-connect/includes/StageStore.php','zoer-connect/readme.txt','zoer-connect/LICENSE'}
    assert z.testzip() is None
print('ZIP layout and deterministic build passed')
