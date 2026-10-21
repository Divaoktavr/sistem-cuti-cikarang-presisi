const mulai=document.querySelector('#mulai'), selesai=document.querySelector('#selesai'), total=document.querySelector('#totalHari');
function hitungHari(){if(!mulai||!selesai||!total)return; if(mulai.value) selesai.min=mulai.value; if(mulai.value&&selesai.value&&selesai.value>=mulai.value){const a=new Date(mulai.value+'T00:00:00'),b=new Date(selesai.value+'T00:00:00');total.value=(Math.round((b-a)/86400000)+1)+' hari';}else total.value='Pilih tanggal terlebih dahulu';}
if(mulai&&selesai){mulai.addEventListener('change',hitungHari);selesai.addEventListener('change',hitungHari);}
