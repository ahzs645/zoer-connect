from pathlib import Path
import subprocess
import re
from zipfile import ZipFile
root = Path(__file__).resolve().parents[1]
subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True, capture_output=True)
version=re.search(r'Version: ([0-9.]+)',(root/'zoer-connect.php').read_text()).group(1)
archive = root/'dist'/f'zoer-connect-{version}.zip'
first = archive.read_bytes()
subprocess.run(['python3', 'scripts/build.py'], cwd=root, check=True, capture_output=True)
assert first == archive.read_bytes(), 'Build must be reproducible'
with ZipFile(archive) as z:
    assert set(z.namelist()) == {'zoer-connect/includes/GitHubUpdater.php','zoer-connect/includes/FileComparison.php','zoer-connect/zoer-connect.php','zoer-connect/includes/Plugin.php','zoer-connect/includes/SnapshotStream.php','zoer-connect/includes/WriteFence.php','zoer-connect/includes/RequestDrain.php','zoer-connect/includes/TransferImport.php','zoer-connect/includes/ImportAdmin.php','zoer-connect/includes/DatabaseExporter.php','zoer-connect/includes/RemoteExport.php','zoer-connect/includes/PagedExport.php','zoer-connect/includes/ConnectionKey.php','zoer-connect/includes/ConnectionAdmin.php','zoer-connect/includes/FilePublication.php','zoer-connect/includes/ChunkedFilePublication.php','zoer-connect/includes/TableStage.php','zoer-connect/includes/Selection.php','zoer-connect/includes/ExportProfile.php','zoer-connect/includes/FileExporter.php','zoer-connect/includes/ExportAdmin.php','zoer-connect/includes/RelatedRows.php','zoer-connect/includes/Replacement.php','zoer-connect/includes/SettingsPreservation.php','zoer-connect/includes/RecoveryCoordinator.php','zoer-connect/includes/StageStore.php','zoer-connect/readme.txt','zoer-connect/LICENSE'}
    assert z.testzip() is None
print('ZIP layout and deterministic build passed')
