<?php
/*
KVM-VDI
Tadas Ustinavičius
2016-08-31
Vilnius, Lithuania.
*/
include ('functions/config.php');
require_once('functions/functions.php');
if (!check_session()){
    header ("Location: $serviceurl/?error=1");
    exit;
}
slash_vars();
$machine_type=remove_specialchars($_POST['machine_type']);
$hypervisor=remove_specialchars($_POST['hypervisor']);
$source_volume=remove_specialchars($_POST['source_volume']);
$source_drivepath=remove_specialchars($_POST['source_drivepath']);
$source_drive_size=remove_specialchars($_POST['source_drive_size']);
$iso_image='';
if (isset($_POST['iso_image']))
    $iso_image=remove_specialchars($_POST['iso_image']);
if (isset($_POST['iso_path']))
    $iso_path=$_POST['iso_path'];
$numsock=remove_specialchars($_POST['numsock']);
$numcore=remove_specialchars($_POST['numcore']);
$numram=1024*remove_specialchars($_POST['numram']);
$network=remove_specialchars($_POST['network']);
$machinename=remove_specialchars($_POST['machinename']);
$machinecount=remove_specialchars($_POST['machinecount']);
$os_type=remove_specialchars($_POST['os_type']);
$os_version=remove_specialchars($_POST['os_version']);
$numcpu=$numsock*$numcore;
if (check_empty($machine_type,$hypervisor,$numsock,$numcore,$numram,$network,$machinename,$machinecount)){
    echo 'MISSING_ARGS';
    exit;
}
$cdrom_cmd="";
if ($iso_image=='on'&&!empty($iso_path)){
        $boot_cmd="--noautoconsole --cdrom " . escapeshellarg($default_iso_path . '/' . $iso_path);
}
else 
    $boot_cmd="--pxe --noautoconsole";
$h_reply=get_SQL_line("SELECT ip, port FROM hypervisors WHERE id='$hypervisor'");
ssh_connect($h_reply[0].":".$h_reply[1]);
if ($machine_type=='simplemachine'||$machine_type=='sourcemachine'){
    $x=0;
    while ($x<$machinecount){
	$name=$machinename;
	if ($machinecount>1)
	    $name=$machinename.sprintf("%0" . strlen($machinecount) . "s", $x+1);
	$existing_vm=get_SQL_array("SELECT * FROM vms WHERE BINARY name='$name'");
	if (!empty($existing_vm)){
	    echo 'VMNAME_EXISTS';
	    exit;
	}
	++$x;
    }
    $x=0;
    while ($x<$machinecount){
	if ($machinecount>1)
	    $name=$machinename.sprintf("%0" . strlen($machinecount) . "s", $x+1);
	else
    	    $name=$machinename;
	$spice_pw=$randomString = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 13);
	$disk=$source_drivepath . '/' . $name . "-" . uniqid() . ".qcow2";
	$vm_cmd="sudo virt-install --name=" . escapeshellarg($name) . " --disk path=" . escapeshellarg($disk) . ",format=qcow2,bus=virtio,cache=none --soundhw=ac97 --vcpus=" . escapeshellarg($numcpu) . ",cores=" . escapeshellarg($numcore). ",sockets=" . escapeshellarg($numsock) . " --ram=" . escapeshellarg($numram) . " --network bridge=" . escapeshellarg($network) . ",model=virtio --os-type=" . escapeshellarg($os_type) . " --os-variant=" . escapeshellarg($os_version) . " --graphics spice,listen=0.0.0.0,password=" . $spice_pw . " --redirdev usb,type=spicevmc --video qxl --noreboot --wait=0 " . $boot_cmd;
	$drive_cmd="sudo qemu-img create -f qcow2 -o size=" . escapeshellarg($source_drive_size) . "G " . escapeshellarg($disk);
	$chown_command="sudo chown $libvirt_user:$libvirt_group $disk";
	$xmledit_cmd="sudo " . $hypervisor_cmdline_path . "/vdi-xmledit -name " . escapeshellarg($name);
	write_log("command: $vm_cmd");
	write_log(ssh_command($drive_cmd,true));
	$vm_reply=ssh_command($vm_cmd,true);
	if (mb_substr($vm_reply, 0, 5 ) !== "ERROR"&&mb_substr($vm_reply, 0, 5 ) !== "usage"){//if return begins with following strings, - something failed
	    add_SQL_line("INSERT INTO  vms (name,hypervisor,machine_type,spice_password,os_type) VALUES ('$name','$hypervisor','$machine_type','$spice_pw','$os_type')");
	    write_log(ssh_command($xmledit_cmd,true));
	}
	write_log($vm_reply);
	++$x;
    }
}
if ($machine_type=='initialmachine'){
    $name=$machinename;
    $existing_vm=get_SQL_array("SELECT * FROM vms WHERE BINARY name='$name'");
    if (!empty($existing_vm)){
        echo 'VMNAME_EXISTS';
        exit;
    }
    $disk=$source_drivepath . '/' . $name . "-" . uniqid() . ".qcow2";
    $vm_cmd="sudo virt-install --name=" . escapeshellarg($name) . " --disk path=" . escapeshellarg($disk) . ",format=qcow2,bus=virtio,cache=none --soundhw=ac97 --vcpus=" . escapeshellarg($numcpu) . ",cores=" . escapeshellarg($numcore). ",sockets=" . escapeshellarg($numsock) . " --ram=" . escapeshellarg($numram) . " --network bridge=" . escapeshellarg($network) . ",sockets=" . escapeshellarg($numsock) . ",model=virtio --os-type=" . escapeshellarg($os_type) . " --os-variant=" . escapeshellarg($os_version) . " --graphics spice,listen=0.0.0.0 --redirdev usb,type=spicevmc --video qxl --import --noreboot --import"; escapeshellarg;
    $drive_cmd="sudo qemu-img create -f qcow2 -o size=1G " . escapeshellarg($disk);
    $chown_command="sudo chown $libvirt_user:$libvirt_group $disk";
    $xmledit_cmd="sudo " . $hypervisor_cmdline_path . "/vdi-xmledit -name " . escapeshellarg($name);
    write_log(ssh_command($drive_cmd,true));
    write_log(ssh_command($chown_command,true));
    write_log(ssh_command($vm_cmd,true));
    write_log(ssh_command($xmledit_cmd,true));
    add_SQL_line("INSERT INTO  vms (name,hypervisor,machine_type,source_volume, os_type) VALUES ('$name','$hypervisor','$machine_type','$source_volume','$os_type')");
    $v_reply=get_SQL_line("SELECT id FROM vms WHERE name='$name'");
    header("Location: $serviceurl/copy_disk.php?vm=" . $v_reply[0] . "&hypervisor=" . $hypervisor);
    exit;
}

if ($machine_type=='vdimachine'){
    $x=0;
    while ($x<$machinecount){
	$name=$machinename.sprintf("%0" . strlen($machinecount) . "s", $x+1);
	$existing_vm=get_SQL_array("SELECT * FROM vms WHERE BINARY name='$name'");
	if (!empty($existing_vm)){
	    echo 'VMNAME_EXISTS';
	    exit;
	}
	++$x;
    }
    $source_reply=get_SQL_line("SELECT name FROM vms WHERE id='$source_volume'");
    $source_disk=str_replace("\n", "",(ssh_command("sudo virsh domblklist $source_reply[0]|grep vda| awk '{print $2}' ",true)));
    if (empty ($source_disk)) //if there is no vda drive, perhaps client uses non virtio controller
        $source_disk=str_replace("\n", "",(ssh_command("sudo virsh domblklist $source_reply[0]|grep hda| awk '{print $2}' ",true)));
    $x=0;
    while ($x<$machinecount){
	$name=$machinename.sprintf("%0" . strlen($machinecount) . "s", $x+1);
	$disk=$source_drivepath . '/' . $name . "-" . uniqid() . ".qcow2";
	$vm_cmd="sudo virt-install --name=" . escapeshellarg($name) . " --disk path=" . escapeshellarg($disk) . ",format=qcow2,bus=virtio,cache=none --soundhw=ac97 --vcpus=" . escapeshellarg($numcpu) . ",cores=" . escapeshellarg($numcore). ",sockets=" . escapeshellarg($numsock) . " --ram=" . escapeshellarg($numram) . " --network bridge=" . escapeshellarg($network) . ",model=virtio --os-type=" . escapeshellarg($os_type) . " --os-variant=" . escapeshellarg($os_version) . " --graphics spice,listen=0.0.0.0 --redirdev usb,type=spicevmc --video qxl --noreboot --import";
	$drive_cmd="sudo qemu-img create -f qcow2 -b " . $source_disk . " " . escapeshellarg($disk);
	$xmledit_cmd="sudo " . $hypervisor_cmdline_path . "/vdi-xmledit -name " . escapeshellarg($name);
	$chown_command="sudo chown $libvirt_user:$libvirt_group $disk";
	write_log(ssh_command($drive_cmd,true));
	write_log(ssh_command($chown_command,true));
	write_log(ssh_command($vm_cmd,true));
	write_log(ssh_command($xmledit_cmd,true));
	add_SQL_line("INSERT INTO  vms (name,hypervisor,machine_type,source_volume,os_type) VALUES ('$name','$hypervisor','$machine_type','$source_volume','$os_type')");
	++$x;

    }
}

echo "SUCCESS";
exit;
?>
