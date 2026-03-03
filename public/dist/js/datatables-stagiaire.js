// Call the dataTables jQuery plugin
/*$(document).ready(function () {
  $('#dataTable').DataTable({
    "language": {
      "url": "https://cdn.datatables.net/plug-ins/1.10.22/i18n/French.json"
    }
  });
});*/


$(document).ready(function () { // Setup - add a text input to each footer cell


  /*$('#dataTable tfoot th').each(function () {

    var title = $(this).text();
    $(this).html('<input type="text" placeholder="' + title + '"/>');
  });*/


  // DataTable
  var table = $('#dataTable').DataTable({
    "language": {
      "url": "https://cdn.datatables.net/plug-ins/1.10.22/i18n/French.json",
      buttons: {
        pageLength: {
          _: "Afficher %d éléments",
          '-1': "Tout afficher"
        }
      }
    },
    initComplete: function () { // Apply the search
      this.api().columns().every(function () {
        var that = this;

        $('input', this.footer()).on('keyup change clear', function () {
          if (that.search() !== this.value) {
            that.search(this.value).draw();
          }
        });
      });
    },
    dom: 'Bfrtip',
    lengthMenu: [
      [10, 25, 50, -1],
      ['10 éléments', '25 éléments', '50 éléments', 'Afficher tout']
    ],
    buttons: [
      'pageLength',
      {
        extend: 'copy',
        text: 'Copier',
      },

      {
        extend: 'csv',
        text: 'Excel',
      },
      {
        extend: 'pdf',
        text: 'PDF',
      },
      {
        extend: 'print',
        text: 'Imprimer',
      },

    ],
    "columns": [{ width: '5%' }, { width: '15%' }, { width: '13%' }, { width: '12%' }, { width: '20%' }, { width: '10%' }, { width: '10%' }, { width: '2%' }, { width: '0%' }],

    "columnDefs": [ {
      "targets": -1,
      "orderable": true
      } ],
    "order": [[ 0, "desc" ]],
    "footerCallback": function (row, data, start, end, display) {
      var api = this.api(), data;

      // Remove the formatting to get integer data for summation
      var intVal = function (i) {
        var str = "" + i;


        i = str.replace(",", ".");
        i = i.replace(' ', '');
        i = i.replace('h', '');
        //alert(i);

        return typeof i === 'string' ?
          i.replace(/[\&nbsp;€,]/g, '') * 1 :
          typeof i === 'number' ?
            i : 0;
      };

      // Total over all pages
      /*totalAction = api
        .column(6)
        .data()
        .reduce(function (a, b) {
          return intVal(a) + intVal(b);
        }, 0);*/

      // Total over this page
      /*pageAction = api
        .column(6, { page: 'current' })
        .data()
        .reduce(function (a, b) {
          return intVal(a) + intVal(b);
        }, 0);

      // Update footer
      $(api.column(6).footer()).html(
        pageTotal4 + 'UVC<br />(' + total4 + 'UVC total)'
      );*/

        //console.log(api.columns().count())
      $(api.column(api.columns().count()-1).footer()).html(
        ''
      );
      $(api.column(0).footer()).html(
        ''
      );



    }
    //responsive: true,
  });
});

