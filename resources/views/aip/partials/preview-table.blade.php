<div class="table-wrap aip-record-preview__table-wrap">
    <table class="data-table aip-upload-preview__table">
        <thead>
            <tr>
                <th scope="col">Pillar</th>
                <th scope="col">Strategic objective</th>
                <th scope="col">Strategy</th>
                <th scope="col">Updated programs / PPAs</th>
                <th scope="col">Resources</th>
                <th scope="col">Budget</th>
                <th scope="col">Persons responsible</th>
                <th scope="col">Timeline</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['pillar'] ?: '—' }}</td>
                    <td>{{ $row['strategic_objective'] ?: '—' }}</td>
                    <td>{{ $row['strategy'] ?: '—' }}</td>
                    <td>{{ $row['ppa'] ?: '—' }}</td>
                    <td>{{ $row['resources'] ?: '—' }}</td>
                    <td>{{ $row['budget'] ?: '—' }}</td>
                    <td>{{ $row['persons_responsible'] ?: '—' }}</td>
                    <td>{{ $row['timeline'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td class="table-empty" colspan="8">No AIP table rows were found in this record.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
